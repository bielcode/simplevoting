<?php

namespace Drupal\simple_voting\Form;

use Drupal\Component\Datetime\TimeInterface;
use Drupal\Core\Cache\Cache;
use Drupal\Core\Database\Connection;
use Drupal\Core\Entity\EntityTypeManagerInterface;
use Drupal\Core\Form\FormBase;
use Drupal\Core\Form\FormStateInterface;
use Drupal\Core\Session\AccountProxyInterface;
use Symfony\Component\DependencyInjection\ContainerInterface;
use Symfony\Component\HttpFoundation\RequestStack;

/**
 * Formulário de votação exibido ao usuário final.
 *
 * Responsabilidade única: renderizar as opções da enquete como radio buttons
 * e persistir o voto. A decisão de bloquear ou não o formulário vem de fora
 * (VotingBlock), mantendo esta classe focada apenas em apresentação e
 * escrita de dados.
 */
class VoteForm extends FormBase {

  public function __construct(
    protected readonly Connection $database,
    protected readonly AccountProxyInterface $currentUser,
    protected readonly EntityTypeManagerInterface $entityTypeManager,
    protected readonly TimeInterface $time,
    protected readonly RequestStack $requestStack,
  ) {}

  /**
   * {@inheritdoc}
   */
  public static function create(ContainerInterface $container): static {
    return new static(
      $container->get('database'),
      $container->get('current_user'),
      $container->get('entity_type.manager'),
      $container->get('datetime.time'),
      $container->get('request_stack'),
    );
  }

  /**
   * {@inheritdoc}
   */
  public function getFormId(): string {
    return 'simple_voting_vote_form';
  }

  /**
   * {@inheritdoc}
   *
   * @param string|null $question_id
   *   Machine name da VotingQuestion configurada no bloco.
   * @param bool $locked
   *   Quando TRUE, o formulário é renderizado em modo somente-leitura. O bloco
   *   determina esse estado consultando o banco e as configurações globais antes
   *   de invocar o formulário, evitando duplicidade de queries.
   */
  public function buildForm(
    array $form,
    FormStateInterface $form_state,
    string $question_id = NULL,
    bool $locked = FALSE,
  ): array {
    if ($question_id === NULL) {
      return $form;
    }

    /** @var \Drupal\simple_voting\Entity\VotingQuestion|null $question */
    $question = $this->entityTypeManager
      ->getStorage('voting_question')
      ->load($question_id);

    if ($question === NULL) {
      $form['notice'] = ['#markup' => $this->t('A enquete solicitada não está disponível.')];
      return $form;
    }

    $options = $this->loadOptions($question_id);
    if (empty($options)) {
      $form['notice'] = ['#markup' => $this->t('Esta enquete ainda não possui opções cadastradas.')];
      return $form;
    }

    $form['question_id'] = [
      '#type'  => 'hidden',
      '#value' => $question_id,
    ];

    $form['option_id'] = [
      '#type'     => 'radios',
      '#title'    => $question->label(),
      '#options'  => array_column($options, 'title', 'id'),
      '#required' => !$locked,
      '#disabled' => $locked,
    ];

    if ($locked) {
      $form['locked_notice'] = [
        '#type'       => 'html_tag',
        '#tag'        => 'p',
        '#value'      => $this->t('Você já participou desta enquete ou a votação está encerrada.'),
        '#attributes' => ['class' => ['messages', 'messages--warning']],
        '#weight'     => 10,
      ];
    }
    else {
      $form['actions'] = [
        '#type'   => 'actions',
        'submit'  => [
          '#type'  => 'submit',
          '#value' => $this->t('Registrar voto'),
        ],
      ];
    }

    $form['#cache'] = [
      'contexts' => ['user'],
      'tags'     => [
        'config:simple_voting.settings',
        'config:simple_voting.question.' . $question_id,
      ],
    ];

    return $form;
  }

  /**
   * {@inheritdoc}
   */
  public function validateForm(array &$form, FormStateInterface $form_state): void {
    if (!$form_state->getValue('option_id')) {
      $form_state->setErrorByName('option_id', $this->t('Selecione uma opção para registrar seu voto.'));
    }
  }

  /**
   * {@inheritdoc}
   */
  public function submitForm(array &$form, FormStateInterface $form_state): void {
    $question_id = $form_state->getValue('question_id');
    $option_id   = (int) $form_state->getValue('option_id');
    $uid         = (int) $this->currentUser->id();

    $already = (bool) $this->database
      ->select('simple_voting_vote', 'v')
      ->fields('v', ['id'])
      ->condition('v.question_id', $question_id)
      ->condition('v.uid', $uid)
      ->range(0, 1)
      ->execute()
      ->fetchField();

    if ($already) {
      $this->messenger()->addWarning($this->t('Seu voto já havia sido computado anteriormente.'));
      return;
    }

    $request = $this->requestStack->getCurrentRequest();

    $this->database->insert('simple_voting_vote')
      ->fields([
        'question_id' => $question_id,
        'option_id'   => $option_id,
        'uid'         => $uid,
        'timestamp'   => $this->time->getRequestTime(),
        'ip'          => $request?->getClientIp() ?? '',
      ])
      ->execute();

    Cache::invalidateTags(['simple_voting:question:' . $question_id]);

    $form_state->setRedirect('simple_voting.results', ['question_id' => $question_id]);
  }

  private function loadOptions(string $question_id): array {
    return $this->database
      ->select('simple_voting_option', 'o')
      ->fields('o', ['id', 'title', 'description', 'image_fid', 'weight'])
      ->condition('o.question_id', $question_id)
      ->orderBy('o.weight')
      ->execute()
      ->fetchAll(\PDO::FETCH_ASSOC);
  }

}
