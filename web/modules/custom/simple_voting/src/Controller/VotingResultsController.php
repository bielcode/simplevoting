<?php

namespace Drupal\simple_voting\Controller;

use Drupal\Core\Controller\ControllerBase;
use Drupal\Core\Database\Connection;
use Drupal\Core\StringTranslation\TranslatableMarkup;
use Drupal\Core\Url;
use Symfony\Component\DependencyInjection\ContainerInterface;
use Symfony\Component\HttpKernel\Exception\NotFoundHttpException;

/**
 * Página de resultados de uma enquete.
 *
 * Exibida após o submit do VoteForm. O conteúdo varia conforme a configuração
 * show_results da questão: quando habilitado, mostra a tabela com percentuais;
 * caso contrário, apenas confirma o registro do voto sem revelar números.
 */
class VotingResultsController extends ControllerBase {

  public function __construct(
    protected readonly Connection $database,
  ) {}

  /**
   * {@inheritdoc}
   */
  public static function create(ContainerInterface $container): static {
    return new static($container->get('database'));
  }

  /**
   * Callback de título da rota — usa o label da enquete quando disponível.
   */
  public function title(string $question_id): TranslatableMarkup|string {
    $question = $this->entityTypeManager()
      ->getStorage('voting_question')
      ->load($question_id);

    return $question ? $question->label() : $this->t('Resultado da Enquete');
  }

  /**
   * Renderiza os resultados (ou a confirmação) para a enquete solicitada.
   */
  public function results(string $question_id): array {
    $question = $this->entityTypeManager()
      ->getStorage('voting_question')
      ->load($question_id);

    if ($question === NULL) {
      throw new NotFoundHttpException();
    }

    $build = [
      '#cache' => [
        'tags'     => ['simple_voting:question:' . $question_id],
        'contexts' => ['user'],
      ],
    ];

    if (!$question->showsResults()) {
      $build['message'] = [
        '#type'       => 'html_tag',
        '#tag'        => 'p',
        '#value'      => $this->t('Seu voto foi registrado.'),
        '#attributes' => ['class' => ['messages', 'messages--status']],
      ];

      return $build;
    }

    ['rows' => $rows, 'total' => $total] = $this->computeResults($question_id);

    $build['results_table'] = [
      '#type'       => 'table',
      '#caption'    => $question->label(),
      '#attributes' => ['class' => ['sv-results-table']],
      '#header'     => [
        $this->t('Opção'),
        $this->t('Votos'),
        $this->t('Percentual'),
      ],
      '#rows'       => $rows,
      '#empty'      => $this->t('Nenhum voto registrado ainda.'),
    ];

    $vote_url = Url::fromRoute('simple_voting.vote_page', ['question_id' => $question_id])->toString();
    $build['total'] = [
      '#type'       => 'html_tag',
      '#tag'        => 'p',
      '#value'      => $this->t('Total de votos: <strong>@total</strong> · <a href="@url" class="sv-back-link">Ver Enquete</a>', ['@total' => $total, '@url' => $vote_url]),
      '#attributes' => ['class' => ['simple-voting-total']],
    ];

    return $build;
  }

  /**
   * Carrega as opções com contagem de votos e calcula os percentuais.
   *
   * @return array{rows: array, total: int}
   */
  private function computeResults(string $question_id): array {
    $query = $this->database->select('simple_voting_option', 'o');
    $query->fields('o', ['id', 'title', 'weight']);
    $query->condition('o.question_id', $question_id);
    $query->leftJoin('simple_voting_vote', 'v', '[v].[option_id] = [o].[id]');
    $query->addExpression('COUNT([v].[id])', 'votes');
    $query->groupBy('o.id');
    $query->groupBy('o.title');
    $query->groupBy('o.weight');
    $query->orderBy('o.weight');

    $options = $query->execute()->fetchAll(\PDO::FETCH_ASSOC);
    $total   = (int) array_sum(array_column($options, 'votes'));

    $rows = [];
    foreach ($options as $opt) {
      $pct    = $total > 0 ? round(($opt['votes'] / $total) * 100, 1) : 0;
      $rows[] = [$opt['title'], $opt['votes'], $pct . '%'];
    }

    return ['rows' => $rows, 'total' => $total];
  }

}
