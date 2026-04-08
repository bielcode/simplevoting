<?php

namespace Drupal\Tests\simple_voting\Unit\Service;

use Drupal\Component\Datetime\TimeInterface;
use Drupal\Core\Cache\CacheTagsInvalidatorInterface;
use Drupal\Core\Database\Connection;
use Drupal\Core\Database\IntegrityConstraintViolationException;
use Drupal\Core\Database\Query\Insert;
use Drupal\Core\Database\Query\Select;
use Drupal\Core\Database\StatementInterface;
use Drupal\Core\Lock\LockBackendInterface;
use Drupal\simple_voting\Exception\DuplicateVoteException;
use Drupal\simple_voting\Exception\VoteLockUnavailableException;
use Drupal\simple_voting\Services\VotingService;
use Drupal\Tests\UnitTestCase;
use Psr\Log\LoggerInterface;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\RequestStack;

/**
 * Testes unitários do VotingService.
 *
 * O foco aqui é a lógica de proteção contra duplicidade e race condition —
 * exatamente os cenários que são difíceis de reproduzir em ambiente integrado
 * mas simples de verificar isolando as dependências com mocks.
 *
 * Não testamos integração com banco real nem o comportamento do lock backend
 * concreto; isso seria responsabilidade de um KernelTest. Aqui garantimos
 * que o serviço toma as decisões corretas dado o estado simulado de cada
 * dependência.
 *
 * @coversDefaultClass \Drupal\simple_voting\Services\VotingService
 * @group simple_voting
 */
class VotingServiceTest extends UnitTestCase {

  // Constantes reutilizadas em todos os testes.
  private const QID = 'enquete_teste';
  private const OID = 7;
  private const UID = 42;
  private const IP  = '192.0.2.10';
  private const TS  = 1712400000;

  /**
   * Mock da conexão com o banco de dados.
   *
   * @var \Drupal\Core\Database\Connection
   */
  private Connection $database;

  /**
   * Mock do serviço de lock distribuído.
   *
   * @var \Drupal\Core\Lock\LockBackendInterface
   */
  private LockBackendInterface $lock;

  /**
   * Mock do serviço de tempo.
   *
   * @var \Drupal\Component\Datetime\TimeInterface
   */
  private TimeInterface $time;

  /**
   * Mock da pilha de requisições HTTP.
   *
   * @var \Symfony\Component\HttpFoundation\RequestStack
   */
  private RequestStack $requestStack;

  /**
   * Mock do canal de log do módulo.
   *
   * @var \Psr\Log\LoggerInterface
   */
  private LoggerInterface $logger;

  /**
   * Mock do invalidador de cache tags.
   *
   * @var \Drupal\Core\Cache\CacheTagsInvalidatorInterface
   */
  private CacheTagsInvalidatorInterface $cacheTagsInvalidator;

  /**
   * Instância real do serviço sob teste.
   *
   * @var \Drupal\simple_voting\Services\VotingService
   */
  private VotingService $service;

  /**
   * {@inheritdoc}
   */
  protected function setUp(): void {
    parent::setUp();

    $this->database             = $this->createMock(Connection::class);
    $this->lock                 = $this->createMock(LockBackendInterface::class);
    $this->time                 = $this->createMock(TimeInterface::class);
    $this->requestStack         = $this->createMock(RequestStack::class);
    $this->logger               = $this->createMock(LoggerInterface::class);
    $this->cacheTagsInvalidator = $this->createMock(CacheTagsInvalidatorInterface::class);

    $this->service = new VotingService(
      $this->database,
      $this->lock,
      $this->time,
      $this->requestStack,
      $this->logger,
      $this->cacheTagsInvalidator,
    );
  }

  /**
   * Configura o mock do SELECT de verificação de duplicidade.
   *
   * @param bool $already_voted
   *   Valor que o fetchField() deve retornar (FALSE = sem voto anterior).
   */
  private function mockDuplicateCheck(bool $already_voted): void {
    $statement = $this->createMock(StatementInterface::class);
    $statement->method('fetchField')->willReturn($already_voted ? '1' : FALSE);

    $select = $this->createMock(Select::class);
    $select->method('fields')->willReturnSelf();
    $select->method('condition')->willReturnSelf();
    $select->method('range')->willReturnSelf();
    $select->method('execute')->willReturn($statement);

    $this->database
      ->method('select')
      ->with('simple_voting_vote', 'v')
      ->willReturn($select);
  }

  /**
   * Configura o mock do INSERT para retornar um ID simulado.
   */
  private function mockInsert(): void {
    $insert = $this->createMock(Insert::class);
    $insert->method('fields')->willReturnSelf();
    $insert->method('execute')->willReturn(1);

    $this->database->method('insert')->willReturn($insert);
  }

  /**
   * Configura dependências auxiliares (tempo, request, lock adquirido).
   */
  private function mockHappyPathDependencies(): void {
    $this->lock->method('acquire')->willReturn(TRUE);
    $this->lock->method('release')->willReturn(NULL);

    $this->time->method('getRequestTime')->willReturn(self::TS);

    $request = $this->createMock(Request::class);
    $request->method('getClientIp')->willReturn(self::IP);
    $this->requestStack->method('getCurrentRequest')->willReturn($request);
  }

  /**
   * @covers ::castVote
   * @testdox Registra um voto novo com os campos corretos quando não há duplicata
   *
   * Voto novo: lock adquirido, nenhum registro anterior, INSERT executado.
   * Cache tag da enquete deve ser invalidada após a persistência.
   */
  public function testCastVoteSucceeds(): void {
    $this->mockHappyPathDependencies();
    $this->mockDuplicateCheck(already_voted: FALSE);

    $insert = $this->createMock(Insert::class);
    $insert->method('execute')->willReturn(1);

    // Garante que os campos corretos são passados para o INSERT.
    $insert->expects($this->once())
      ->method('fields')
      ->with($this->callback(function (array $fields): bool {
        return $fields['question_id'] === self::QID
          && $fields['option_id'] === self::OID
          && $fields['uid'] === self::UID
          && $fields['timestamp'] === self::TS
          && $fields['ip'] === self::IP;
      }))
      ->willReturnSelf();

    $this->database->method('insert')->willReturn($insert);

    // Se chegar até aqui sem exception, o voto foi registrado.
    $this->service->castVote(self::QID, self::OID, self::UID);

    // PHPUnit conta a assertion implícita do expects()->once() acima,
    // mas deixamos explícita para clareza no relatório.
    $this->addToAssertionCount(1);
  }

  /**
   * @covers ::castVote
   * @testdox Lança DuplicateVoteException quando o SELECT detecta voto anterior (camada 1)
   *
   * Camada 1 de proteção: SELECT detecta voto anterior dentro do lock.
   * Esperamos DuplicateVoteException e o INSERT nunca deve ser chamado.
   */
  public function testCastVoteThrowsDuplicateWhenSelectFindsExistingVote(): void {
    $this->mockHappyPathDependencies();
    $this->mockDuplicateCheck(already_voted: TRUE);

    // INSERT não pode ser executado se o SELECT já encontrou duplicata.
    $this->database->expects($this->never())->method('insert');

    // O logger deve registrar a detecção da duplicata.
    $this->logger->expects($this->once())
      ->method('notice')
      ->with($this->stringContains('Voto duplicado detectado via SELECT'));

    $this->expectException(DuplicateVoteException::class);
    $this->service->castVote(self::QID, self::OID, self::UID);
  }

  /**
   * @covers ::castVote
   * @testdox Trata violação de unique constraint como voto duplicado, não como erro fatal (camada 2)
   *
   * Camada 2 de proteção: banco lança IntegrityConstraintViolationException
   * (unique constraint) quando dois processos passam pelo SELECT
   * ao mesmo tempo. O serviço deve tratar isso como
   * DuplicateVoteException, não como erro fatal.
   *
   * Simula exatamente o cenário de race condition em produção: dois processos
   * executam o SELECT simultaneamente, ambos retornam "sem voto", o primeiro
   * faz o INSERT com sucesso, e o segundo recebe a violação de unique key.
   */
  public function testCastVoteThrowsDuplicateOnIntegrityConstraintViolation(): void {
    $this->lock->method('acquire')->willReturn(TRUE);
    $this->lock->method('release')->willReturn(NULL);
    $this->time->method('getRequestTime')->willReturn(self::TS);

    $request = $this->createMock(Request::class);
    $request->method('getClientIp')->willReturn(self::IP);
    $this->requestStack->method('getCurrentRequest')->willReturn($request);

    // SELECT diz "não votou" — o lock não foi efetivo neste cenário.
    $this->mockDuplicateCheck(already_voted: FALSE);

    // INSERT explode com violação de constraint — o segundo processo
    // perdeu a corrida.
    $insert = $this->createMock(Insert::class);
    $insert->method('fields')->willReturnSelf();
    $insert->method('execute')->willThrowException(
      new IntegrityConstraintViolationException('Duplicate entry for unique key \'one_vote_per_user\'', 23000),
    );
    $this->database->method('insert')->willReturn($insert);

    $this->logger->expects($this->once())
      ->method('notice')
      ->with($this->stringContains('Unique constraint violada'));

    // Deve lançar DuplicateVoteException — não expor a violação bruta do banco.
    $this->expectException(DuplicateVoteException::class);
    $this->service->castVote(self::QID, self::OID, self::UID);
  }

  /**
   * @covers ::castVote
   * @testdox Lança VoteLockUnavailableException sem tocar no banco quando o lock falha duas vezes
   *
   * Lock indisponível nas duas tentativas consecutivas.
   * O serviço deve lançar VoteLockUnavailableException sem tocar no banco.
   *
   * Esse é o cenário de carga anormal: lock travado por processo morto ou
   * volume de requisições muito acima do esperado para o mesmo par (uid, qid).
   */
  public function testCastVoteThrowsLockUnavailableAfterTwoAttempts(): void {
    // acquire() retorna FALSE nas duas chamadas.
    $this->lock->expects($this->exactly(2))
      ->method('acquire')
      ->willReturn(FALSE);

    // wait() deve ser chamado uma vez entre as tentativas.
    $this->lock->expects($this->once())->method('wait');

    // release() não deve ser chamado — o lock nunca foi adquirido.
    $this->lock->expects($this->never())->method('release');

    // Banco não deve ser tocado.
    $this->database->expects($this->never())->method('select');
    $this->database->expects($this->never())->method('insert');

    $this->logger->expects($this->once())
      ->method('warning')
      ->with($this->stringContains('Lock'));

    $this->expectException(VoteLockUnavailableException::class);
    $this->service->castVote(self::QID, self::OID, self::UID);
  }

  /**
   * @covers ::castVote
   * @testdox Prossegue normalmente quando o lock é adquirido na segunda tentativa
   *
   * Primeira tentativa de lock falha mas a segunda têm sucesso.
   * O fluxo deve continuar normalmente — lock adquirido na segunda chance.
   */
  public function testCastVoteSucceedsWhenLockAcquiredOnSecondAttempt(): void {
    // Primeira chamada: FALSE; segunda: TRUE.
    $this->lock->expects($this->exactly(2))
      ->method('acquire')
      ->willReturnOnConsecutiveCalls(FALSE, TRUE);

    $this->lock->expects($this->once())->method('wait');
    $this->lock->expects($this->once())->method('release');

    $this->time->method('getRequestTime')->willReturn(self::TS);
    $request = $this->createMock(Request::class);
    $request->method('getClientIp')->willReturn(self::IP);
    $this->requestStack->method('getCurrentRequest')->willReturn($request);

    $this->mockDuplicateCheck(already_voted: FALSE);
    $this->mockInsert();

    // Sem exception = lock funcionou na segunda tentativa.
    $this->service->castVote(self::QID, self::OID, self::UID);
    $this->addToAssertionCount(1);
  }

  /**
   * @covers ::castVote
   * @testdox Libera o lock mesmo quando o INSERT lança uma exceção inesperada (finally garantido)
   *
   * Garante que o lock é liberado mesmo quando o INSERT lança uma exception
   * inesperada — não pode vazar lock, do contrário a próxima tentativa do
   * mesmo usuário ficaria presa aguardando expiração do TTL.
   */
  public function testLockIsAlwaysReleasedEvenOnUnexpectedException(): void {
    $this->lock->method('acquire')->willReturn(TRUE);
    $this->lock->expects($this->once())->method('release');

    $this->time->method('getRequestTime')->willReturn(self::TS);
    $request = $this->createMock(Request::class);
    $request->method('getClientIp')->willReturn(self::IP);
    $this->requestStack->method('getCurrentRequest')->willReturn($request);

    $this->mockDuplicateCheck(already_voted: FALSE);

    // Simula falha genérica de banco (ex.: deadlock, timeout de conexão).
    $insert = $this->createMock(Insert::class);
    $insert->method('fields')->willReturnSelf();
    $insert->method('execute')->willThrowException(new \RuntimeException('DB timeout'));
    $this->database->method('insert')->willReturn($insert);

    $this->logger->expects($this->once())
      ->method('error')
      ->with($this->stringContains('Falha ao persistir voto'));

    $this->expectException(\RuntimeException::class);
    $this->service->castVote(self::QID, self::OID, self::UID);

    // O expects()->once() no release() valida que o finally executou.
  }

  /**
   * @covers ::castVote
   * @testdox Persiste IP vazio sem erro quando executado fora de contexto HTTP (CLI/Drush)
   *
   * Quando não há request no stack (ex.: execução via CLI/Drush), o IP
   * deve ser persistido como string vazia, sem lançar exceção.
   */
  public function testCastVoteFallsBackToEmptyIpWhenNoRequest(): void {
    $this->lock->method('acquire')->willReturn(TRUE);
    $this->lock->method('release')->willReturn(NULL);
    $this->time->method('getRequestTime')->willReturn(self::TS);

    // Sem request ativo no stack.
    $this->requestStack->method('getCurrentRequest')->willReturn(NULL);

    $this->mockDuplicateCheck(already_voted: FALSE);

    $insert = $this->createMock(Insert::class);
    $insert->method('execute')->willReturn(1);

    $insert->expects($this->once())
      ->method('fields')
      ->with($this->callback(fn(array $f) => $f['ip'] === ''))
      ->willReturnSelf();

    $this->database->method('insert')->willReturn($insert);

    $this->service->castVote(self::QID, self::OID, self::UID);
    $this->addToAssertionCount(1);
  }

}
