<?php

namespace Drupal\simple_voting\Controller;

use Drupal\Component\Datetime\TimeInterface;
use Drupal\Core\Cache\Cache;
use Drupal\Core\Cache\CacheableJsonResponse;
use Drupal\Core\Cache\CacheableMetadata;
use Drupal\Core\Controller\ControllerBase;
use Drupal\Core\Database\Connection;
use Symfony\Component\DependencyInjection\ContainerInterface;
use Symfony\Component\HttpFoundation\JsonResponse;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\HttpKernel\Exception\AccessDeniedHttpException;
use Symfony\Component\HttpKernel\Exception\BadRequestHttpException;
use Symfony\Component\HttpKernel\Exception\ConflictHttpException;
use Symfony\Component\HttpKernel\Exception\NotFoundHttpException;
use Symfony\Component\HttpKernel\Exception\UnprocessableEntityHttpException;

/**
 * API REST v1 para o módulo simple_voting.
 *
 * Todos os endpoints retornam application/json. Autenticação via sessão do
 * Drupal; o endpoint de voto (POST) adicionalmente requer o header
 * X-CSRF-Token, obtido em /session/token — requisito garantido pelo access
 * checker _csrf_request_header_token na rota, não revalidado aqui.
 *
 * Serialização manual: sem JSON:API, sem REST module. A estrutura de resposta
 * envolve os dados em { "data": ... } por convenção, facilitando a extensão
 * futura sem quebrar contratos de versão.
 */
class VotingApiController extends ControllerBase {

  public function __construct(
    protected readonly Connection $database,
    protected readonly TimeInterface $time,
  ) {}

  /**
   * {@inheritdoc}
   */
  public static function create(ContainerInterface $container): static {
    return new static(
      $container->get('database'),
      $container->get('datetime.time'),
    );
  }

  /**
   * GET /api/voting/v1/questions
   *
   * Lista todas as enquetes com status = aberta. A resposta varia conforme
   * as permissões do usuário, então o cache context user.permissions é
   * necessário para evitar que usuários sem acesso recebam cache de outro.
   */
  public function getQuestions(): CacheableJsonResponse {
    $storage = $this->entityTypeManager()->getStorage('voting_question');

    $ids = $storage->getQuery()
      ->accessCheck(FALSE)
      ->condition('status', TRUE)
      ->execute();

    /** @var \Drupal\simple_voting\Entity\VotingQuestionInterface[] $questions */
    $questions = $ids ? $storage->loadMultiple($ids) : [];

    $data = array_values(array_map(
      static fn($q) => [
        'id'           => $q->id(),
        'title'        => $q->label(),
        'show_results' => $q->showsResults(),
      ],
      $questions,
    ));

    $cache = (new CacheableMetadata())
      ->addCacheTags(['config:simple_voting.question_list'])
      ->addCacheContexts(['user.permissions']);

    return (new CacheableJsonResponse(['data' => $data]))
      ->addCacheableDependency($cache);
  }

  /**
   * GET /api/voting/v1/questions/{id}
   *
   * Retorna a enquete identificada pelo machine name {id} com a lista de
   * opções. Campos opcionais (description) só aparecem no payload quando
   * preenchidos — evita poluir a resposta com null desnecessário.
   */
  public function getQuestion(string $id): CacheableJsonResponse {
    $question = $this->entityTypeManager()
      ->getStorage('voting_question')
      ->load($id);

    if ($question === NULL) {
      throw new NotFoundHttpException();
    }

    $options = $this->database
      ->select('simple_voting_option', 'o')
      ->fields('o', ['id', 'title', 'description', 'weight'])
      ->condition('o.question_id', $id)
      ->orderBy('o.weight')
      ->execute()
      ->fetchAll(\PDO::FETCH_ASSOC);

    $data = [
      'id'           => $question->id(),
      'title'        => $question->label(),
      'status'       => $question->isOpen() ? 'open' : 'closed',
      'show_results' => $question->showsResults(),
      'options'      => array_values(array_map(
        static function (array $opt): array {
          $item = [
            'id'    => (int) $opt['id'],
            'title' => $opt['title'],
          ];
          if (!empty($opt['description'])) {
            $item['description'] = $opt['description'];
          }
          return $item;
        },
        $options,
      )),
    ];

    $cache = (new CacheableMetadata())
      ->addCacheTags([
        'config:simple_voting.question.' . $id,
        'simple_voting:question:' . $id,
      ])
      ->addCacheContexts(['user.permissions']);

    return (new CacheableJsonResponse(['data' => $data]))
      ->addCacheableDependency($cache);
  }

  /**
   * POST /api/voting/v1/votes
   *
   * Registra um voto. Body JSON esperado:
   *   { "question_id": "machine_name", "option_id": 42 }
   *
   * Autenticação via Basic Auth ou sessão Drupal (cookie + X-CSRF-Token).
   * O roteador já bloqueia anônimos com 401, mas verificamos explicitamente
   * aqui para garantir uma resposta JSON bem formada em vez de HTML/redirect.
   */
  public function castVote(Request $request): JsonResponse {
    if ($this->currentUser()->isAnonymous()) {
      return new JsonResponse(
        ['error' => 'Autenticação necessária para registrar um voto.'],
        Response::HTTP_UNAUTHORIZED,
        ['WWW-Authenticate' => 'Basic realm="Simple Voting API"'],
      );
    }

    $payload     = $this->decodeJsonBody($request);
    $question_id = $payload['question_id'] ?? NULL;
    $option_id   = isset($payload['option_id']) ? (int) $payload['option_id'] : NULL;

    if (empty($question_id) || !$option_id) {
      throw new BadRequestHttpException('Os campos question_id e option_id são obrigatórios.');
    }

    $question = $this->entityTypeManager()
      ->getStorage('voting_question')
      ->load($question_id);

    if ($question === NULL || !$question->isOpen()) {
      throw new NotFoundHttpException('Enquete não encontrada ou não está aberta para votação.');
    }

    $option_exists = (bool) $this->database
      ->select('simple_voting_option', 'o')
      ->fields('o', ['id'])
      ->condition('o.id', $option_id)
      ->condition('o.question_id', $question_id)
      ->range(0, 1)
      ->execute()
      ->fetchField();

    if (!$option_exists) {
      throw new UnprocessableEntityHttpException('A opção informada não existe ou não pertence a esta enquete.');
    }

    $uid = (int) $this->currentUser()->id();

    $already_voted = (bool) $this->database
      ->select('simple_voting_vote', 'v')
      ->fields('v', ['id'])
      ->condition('v.question_id', $question_id)
      ->condition('v.uid', $uid)
      ->range(0, 1)
      ->execute()
      ->fetchField();

    if ($already_voted) {
      throw new ConflictHttpException('Você já registrou um voto nesta enquete.');
    }

    $this->database->insert('simple_voting_vote')
      ->fields([
        'question_id' => $question_id,
        'option_id'   => $option_id,
        'uid'         => $uid,
        'timestamp'   => $this->time->getRequestTime(),
        'ip'          => $request->getClientIp() ?? '',
      ])
      ->execute();

    Cache::invalidateTags(['simple_voting:question:' . $question_id]);

    return new JsonResponse(['message' => 'Voto registrado com sucesso.'], JsonResponse::HTTP_CREATED);
  }

  /**
   * GET /api/voting/v1/questions/{id}/results
   *
   * Retorna os resultados da enquete com contagem e percentual por opção.
   *
   * Visibilidade: se show_results = FALSE, somente usuários com a permissão
   * 'view voting results' (geralmente administradores) recebem os dados.
   * Usuários comuns recebem 403 — isso é intencional e documentado na config
   * da enquete.
   */
  public function getResults(string $id): CacheableJsonResponse {
    $question = $this->entityTypeManager()
      ->getStorage('voting_question')
      ->load($id);

    if ($question === NULL) {
      throw new NotFoundHttpException();
    }

    $can_bypass = $this->currentUser()->hasPermission('view voting results');

    if (!$question->showsResults() && !$can_bypass) {
      throw new AccessDeniedHttpException('Os resultados desta enquete não estão disponíveis.');
    }

    ['options' => $options, 'total' => $total] = $this->computeOptionResults($id);

    $data = [
      'question_id'    => $id,
      'question_title' => $question->label(),
      'total_votes'    => $total,
      'options'        => $options,
    ];

    $cache = (new CacheableMetadata())
      ->addCacheTags([
        'config:simple_voting.question.' . $id,
        'simple_voting:question:' . $id,
      ])
      ->addCacheContexts(['user.permissions']);

    return (new CacheableJsonResponse(['data' => $data]))
      ->addCacheableDependency($cache);
  }

  /**
   * Carrega as opções com contagem de votos e percentual calculado.
   *
   * Separado em método próprio por ser lógica reutilizável — mesma query
   * base do VotingResultsController, adaptada para retornar estrutura
   * adequada a JSON em vez de render array.
   *
   * @return array{options: array, total: int}
   */
  private function computeOptionResults(string $question_id): array {
    $query = $this->database->select('simple_voting_option', 'o');
    $query->fields('o', ['id', 'title', 'weight']);
    $query->condition('o.question_id', $question_id);
    $query->leftJoin('simple_voting_vote', 'v', '[v].[option_id] = [o].[id]');
    $query->addExpression('COUNT([v].[id])', 'votes');
    $query->groupBy('o.id');
    $query->groupBy('o.title');
    $query->groupBy('o.weight');
    $query->orderBy('o.weight');

    $rows  = $query->execute()->fetchAll(\PDO::FETCH_ASSOC);
    $total = (int) array_sum(array_column($rows, 'votes'));

    $options = array_values(array_map(static function (array $row) use ($total): array {
      $votes = (int) $row['votes'];
      return [
        'id'         => (int) $row['id'],
        'title'      => $row['title'],
        'votes'      => $votes,
        'percentage' => $total > 0 ? round(($votes / $total) * 100, 1) : 0.0,
      ];
    }, $rows));

    return ['options' => $options, 'total' => $total];
  }

  /**
   * Decodifica e valida o body JSON da request.
   *
   * Retorna array associativo ou lança BadRequestHttpException com mensagem
   * descritiva — sem expor internals do servidor na mensagem de erro.
   *
   * @return array<string, mixed>
   */
  private function decodeJsonBody(Request $request): array {
    $content = $request->getContent();

    if (empty($content)) {
      throw new BadRequestHttpException('O body da requisição não pode estar vazio.');
    }

    $data = json_decode($content, TRUE);

    if (json_last_error() !== JSON_ERROR_NONE) {
      throw new BadRequestHttpException('JSON inválido no body da requisição.');
    }

    return (array) $data;
  }

}
