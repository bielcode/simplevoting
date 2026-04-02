<?php

namespace Drupal\simple_voting\Plugin\Block;

use Drupal\Core\Block\BlockBase;
use Drupal\Core\Config\ConfigFactoryInterface;
use Drupal\Core\Database\Connection;
use Drupal\Core\Entity\EntityTypeManagerInterface;
use Drupal\Core\Form\FormBuilderInterface;
use Drupal\Core\Form\FormStateInterface;
use Drupal\Core\Plugin\ContainerFactoryPluginInterface;
use Drupal\Core\Session\AccountProxyInterface;
use Drupal\simple_voting\Entity\VotingQuestionInterface;
use Drupal\simple_voting\Form\VoteForm;
use Symfony\Component\DependencyInjection\ContainerInterface;

/**
 * Bloco que renderiza um formulário de votação para uma enquete configurável.
 *
 * O administrador seleciona a enquete no formulário de configuração do bloco.
 * Antes de delegar a renderização ao VoteForm, este bloco resolve o estado de
 * bloqueio: verifica se o sistema de votação está habilitado globalmente e se
 * o usuário corrente já registrou um voto nesta questão. Isso mantém o VoteForm
 * focado apenas em apresentação, sem precisar replicar regras de negócio.
 *
 * @Block(
 *   id = "simple_voting_block",
 *   admin_label = @Translation("Simple Voting: Formulário de Votação"),
 *   category = @Translation("Simple Voting"),
 * )
 */
class VotingBlock extends BlockBase implements ContainerFactoryPluginInterface {

  public function __construct(
    array $configuration,
    string $plugin_id,
    mixed $plugin_definition,
    protected readonly Connection $database,
    protected readonly AccountProxyInterface $currentUser,
    protected readonly ConfigFactoryInterface $configFactory,
    protected readonly FormBuilderInterface $formBuilder,
    protected readonly EntityTypeManagerInterface $entityTypeManager,
  ) {
    parent::__construct($configuration, $plugin_id, $plugin_definition);
  }

  /**
   * {@inheritdoc}
   */
  public static function create(
    ContainerInterface $container,
    array $configuration,
    $plugin_id,
    $plugin_definition,
  ): static {
    return new static(
      $configuration,
      $plugin_id,
      $plugin_definition,
      $container->get('database'),
      $container->get('current_user'),
      $container->get('config.factory'),
      $container->get('form_builder'),
      $container->get('entity_type.manager'),
    );
  }

  /**
   * {@inheritdoc}
   */
  public function defaultConfiguration(): array {
    return ['question_id' => ''] + parent::defaultConfiguration();
  }

  /**
   * {@inheritdoc}
   */
  public function blockForm($form, FormStateInterface $form_state): array {
    $form = parent::blockForm($form, $form_state);

    $questions = $this->entityTypeManager
      ->getStorage('voting_question')
      ->loadMultiple();

    $options = ['' => $this->t('— Selecione uma enquete —')];
    foreach ($questions as $question) {
      $options[$question->id()] = $question->label();
    }

    $form['question_id'] = [
      '#type'          => 'select',
      '#title'         => $this->t('Enquete'),
      '#description'   => $this->t('A enquete cujas opções serão exibidas neste bloco.'),
      '#options'       => $options,
      '#default_value' => $this->configuration['question_id'],
      '#required'      => TRUE,
    ];

    return $form;
  }

  /**
   * {@inheritdoc}
   */
  public function blockSubmit($form, FormStateInterface $form_state): void {
    $this->configuration['question_id'] = $form_state->getValue('question_id');
  }

  /**
   * {@inheritdoc}
   */
  public function build(): array {
    $question_id = $this->configuration['question_id'];

    if (empty($question_id)) {
      return [];
    }

    if (!$this->currentUser->hasPermission('vote in polls')) {
      return [
        '#cache' => ['contexts' => ['user.permissions']],
      ];
    }

    $global_enabled = (bool) $this->configFactory
      ->get('simple_voting.settings')
      ->get('voting_enabled');

    $uid = (int) $this->currentUser->id();

    $already_voted = (bool) $this->database
      ->select('simple_voting_vote', 'v')
      ->fields('v', ['id'])
      ->condition('v.question_id', $question_id)
      ->condition('v.uid', $uid)
      ->range(0, 1)
      ->execute()
      ->fetchField();

    $locked = !$global_enabled || $already_voted;

    if (!$locked) {
      return $this->formBuilder->getForm(VoteForm::class, $question_id, FALSE);
    }

    /** @var \Drupal\simple_voting\Entity\VotingQuestionInterface|null $question */
    $question = $this->entityTypeManager
      ->getStorage('voting_question')
      ->load($question_id);

    if ($question !== NULL && $question->showsResults()) {
      return $this->buildResultsRenderArray($question, $question_id);
    }

    $message = $already_voted
      ? $this->t('Seu voto foi registrado.')
      : $this->t('A votação está encerrada.');

    return [
      '#type'       => 'html_tag',
      '#tag'        => 'p',
      '#value'      => $message,
      '#attributes' => ['class' => ['messages', 'messages--status']],
    ];
  }

  /**
   * Monta o render array de resultados exibido no bloco após a votação.
   *
   * O cache é cobertura do próprio bloco via getCacheTags/getCacheContexts;
   * as tags declaradas aqui garantem o bubbling correto em contextos aninhados.
   */
  private function buildResultsRenderArray(VotingQuestionInterface $question, string $question_id): array {
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

    return [
      'results_table' => [
        '#type'    => 'table',
        '#caption' => $question->label(),
        '#header'  => [
          $this->t('Opção'),
          $this->t('Votos'),
          $this->t('%'),
        ],
        '#rows'    => $rows,
        '#empty'   => $this->t('Nenhum voto registrado ainda.'),
      ],
      'total' => [
        '#type'  => 'html_tag',
        '#tag'   => 'p',
        '#value' => $this->t('Total: @total votos', ['@total' => $total]),
      ],
      '#cache' => [
        'tags'     => ['simple_voting:question:' . $question_id],
        'contexts' => ['user', 'user.permissions'],
      ],
    ];
  }

  /**
   * {@inheritdoc}
   *
   * A variação por usuário é necessária porque o estado de "já votou" difere
   * entre visitantes. O render cache do formulário garante o bubbling, mas
   * declaramos aqui também para que o Block Cache não ignore a variação.
   */
  public function getCacheContexts(): array {
    return array_merge(parent::getCacheContexts(), ['user', 'user.permissions']);
  }

  /**
   * {@inheritdoc}
   */
  public function getCacheTags(): array {
    $tags = parent::getCacheTags();

    if (!empty($this->configuration['question_id'])) {
      $qid    = $this->configuration['question_id'];
      $tags[] = 'config:simple_voting.settings';
      $tags[] = 'config:simple_voting.question.' . $qid;
      $tags[] = 'simple_voting:question:' . $qid;
    }

    return $tags;
  }

}
