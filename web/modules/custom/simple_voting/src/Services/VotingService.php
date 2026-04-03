<?php

namespace Drupal\simple_voting\Services;

use Drupal\Component\Datetime\TimeInterface;
use Drupal\Core\Cache\Cache;
use Drupal\Core\Database\Connection;
use Drupal\Core\Database\IntegrityConstraintViolationException;
use Drupal\Core\Lock\LockBackendInterface;
use Drupal\simple_voting\Exception\DuplicateVoteException;
use Drupal\simple_voting\Exception\VoteLockUnavailableException;
use Psr\Log\LoggerInterface;
use Symfony\Component\HttpFoundation\RequestStack;

/**
 * Serviço responsável pelo registro de votos com proteção contra race condition.
 *
 * A regra de negócio é simples — um usuário vota exatamente uma vez por
 * enquete — mas precisa ser garantida mesmo quando múltiplos processos PHP
 * recebem a requisição quase simultaneamente (duplo clique, retry automático
 * do cliente, balancer que reenvia antes do timeout, etc.).
 *
 * A proteção é implementada em duas camadas independentes e complementares:
 *
 * Camada 1 — Lock de aplicação (LockBackendInterface):
 *   O primeiro processo a adquirir o lock executa o SELECT de duplicidade e o
 *   INSERT de forma logicamente atômica. Qualquer outro processo concorrente
 *   para o mesmo par (uid, question_id) fica bloqueado na chamada ::acquire()
 *   e, ao obter o lock, já encontra o voto no banco.
 *
 *   Por quê usar lock em vez de apenas o SELECT + INSERT?
 *   Sem o lock, dois processos podem executar o SELECT simultaneamente, ambos
 *   retornar "não votou ainda" e ambos prosseguir para o INSERT. A unique
 *   constraint barraria o segundo INSERT, mas o primeiro processo também
 *   lançaria exception se o banco rejeitar o winner — comportamento que
 *   depende da ordem de commit, imprevisível. O lock elimina essa ambiguidade.
 *
 * Camada 2 — Unique constraint no banco (question_id, uid):
 *   Garante integridade mesmo em cenários que o lock de aplicação não cobre:
 *   - Múltiplos servidores web sem memcache/Redis compartilhado (lock local
 *     não é visível entre máquinas).
 *   - Falha de infraestrutura que derruba o processo antes de liberar o lock,
 *     permitindo que outro servidor adquira um lock "novo" enquanto o INSERT
 *     do processo anterior ainda está em andamento.
 *   - Código legado ou path alternativo que insira votos sem passar por aqui.
 *
 *   Nessas situações a IntegrityConstraintViolationException do banco é tratada
 *   como duplicata normal, não como erro fatal — a opção correta do ponto de
 *   vista de negócio.
 */
class VotingService {

  public function __construct(
    protected readonly Connection $database,
    protected readonly LockBackendInterface $lock,
    protected readonly TimeInterface $time,
    protected readonly RequestStack $requestStack,
    protected readonly LoggerInterface $logger,
  ) {}

  /**
   * Registra um voto, garantindo que o usuário vote exatamente uma vez.
   *
   * A validação de negócio (enquete aberta, opção válida) é responsabilidade
   * do caller — este método assume que os IDs passados já foram conferidos.
   *
   * @param string $question_id
   *   Machine name da VotingQuestion.
   * @param int $option_id
   *   ID da opção escolhida.
   * @param int $uid
   *   UID do usuário votante.
   *
   * @throws \Drupal\simple_voting\Exception\DuplicateVoteException
   *   Quando o usuário já votou nesta enquete — seja detectado pelo SELECT
   *   dentro do lock, seja pela unique constraint do banco.
   * @throws \Drupal\simple_voting\Exception\VoteLockUnavailableException
   *   Quando o lock não pode ser adquirido em duas tentativas consecutivas.
   */
  public function castVote(string $question_id, int $option_id, int $uid): void {
    $lock_key = "simple_voting_vote_{$uid}_{$question_id}";

    if (!$this->lock->acquire($lock_key)) {
      $this->lock->wait($lock_key);

      if (!$this->lock->acquire($lock_key)) {
        $this->logger->warning(
          'Lock @key indisponível após duas tentativas. Possível lock travado ou carga anormal.',
          ['@key' => $lock_key],
        );
        throw new VoteLockUnavailableException(
          sprintf('Lock %s indisponível após duas tentativas.', $lock_key),
        );
      }
    }

    try {
      $already_voted = (bool) $this->database
        ->select('simple_voting_vote', 'v')
        ->fields('v', ['id'])
        ->condition('v.question_id', $question_id)
        ->condition('v.uid', $uid)
        ->range(0, 1)
        ->execute()
        ->fetchField();

      if ($already_voted) {
        $this->logger->notice(
          'Voto duplicado detectado via SELECT: uid=@uid, enquete=@qid.',
          ['@uid' => $uid, '@qid' => $question_id],
        );
        throw new DuplicateVoteException();
      }

      $ip = $this->requestStack->getCurrentRequest()?->getClientIp() ?? '';

      $this->database->insert('simple_voting_vote')
        ->fields([
          'question_id' => $question_id,
          'option_id'   => $option_id,
          'uid'         => $uid,
          'timestamp'   => $this->time->getRequestTime(),
          'ip'          => $ip,
        ])
        ->execute();
    }
    catch (IntegrityConstraintViolationException $e) {
      $this->logger->notice(
        'Unique constraint violada para uid=@uid question=@qid — lock de aplicação não foi efetivo. Verifique a configuração do lock backend.',
        ['@uid' => $uid, '@qid' => $question_id],
      );
      throw new DuplicateVoteException();
    }
    catch (DuplicateVoteException $e) {
      // Já logada no ponto de detecção — apenas propaga.
      throw $e;
    }
    catch (\Exception $e) {
      $this->logger->error(
        'Falha ao persistir voto: uid=@uid, enquete=@qid. Erro: @message',
        ['@uid' => $uid, '@qid' => $question_id, '@message' => $e->getMessage(), 'exception' => $e],
      );
      throw $e;
    }
    finally {
      $this->lock->release($lock_key);
    }

    Cache::invalidateTags(['simple_voting:question:' . $question_id]);
  }

}
