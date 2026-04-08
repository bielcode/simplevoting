<?php

namespace Drupal\simple_voting\Form;

use Drupal\Core\Database\Connection;
use Drupal\Core\Entity\EntityTypeManagerInterface;
use Drupal\Core\File\FileUrlGeneratorInterface;
use Drupal\Core\Form\FormBase;
use Drupal\Core\Form\FormStateInterface;
use Drupal\Core\Render\Markup;
use Drupal\Core\Session\AccountProxyInterface;
use Drupal\Core\Url;
use Drupal\simple_voting\Exception\DuplicateVoteException;
use Drupal\simple_voting\Exception\VoteLockUnavailableException;
use Drupal\simple_voting\Services\VotingService;
use Symfony\Component\DependencyInjection\ContainerInterface;

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
    protected readonly VotingService $votingService,
    protected readonly FileUrlGeneratorInterface $fileUrlGenerator,
  ) {}

  /**
   * {@inheritdoc}
   */
  public static function create(ContainerInterface $container): static {
    return new static(
      $container->get('database'),
      $container->get('current_user'),
      $container->get('entity_type.manager'),
      $container->get('simple_voting.voting'),
      $container->get('file_url_generator'),
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
   * @param array $form
   *   An associative array containing the structure of the form.
   * @param \Drupal\Core\Form\FormStateInterface $form_state
   *   The current state of the form.
   * @param string|null $question_id
   *   Machine name da VotingQuestion configurada no bloco.
   * @param bool $locked
   *   Quando TRUE, o formulário é renderizado em modo somente-leitura.
   * @param int|null $voted_option_id
   *   ID da opção já escolhida pelo usuário (NULL se ainda não votou).
   * @param bool $show_results
   *   Se TRUE, exibe link para a página de resultados.
   */
  public function buildForm(
    array $form,
    FormStateInterface $form_state,
    ?string $question_id = NULL,
    bool $locked = FALSE,
    ?int $voted_option_id = NULL,
    bool $show_results = FALSE,
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
      '#type'          => 'radios',
      '#title'         => $question->label(),
      '#options'       => array_column($options, 'title', 'id'),
      '#required'      => !$locked,
      '#disabled'      => $locked,
      '#default_value' => $voted_option_id,
    ];

    // Preencher descrição e imagem em cada opção de rádio.
    foreach ($options as $option) {
      $parts = [];

      // Imagem da opção (quando houver).
      if (!empty($option['image_fid'])) {
        $file = $this->entityTypeManager->getStorage('file')->load((int) $option['image_fid']);
        if ($file !== NULL) {
          $image_url = $this->fileUrlGenerator->generateString($file->getFileUri());
          $parts[] = '<img class="sv-option-img" src="' . htmlspecialchars($image_url, ENT_QUOTES, 'UTF-8') . '" alt="" loading="lazy" />';
        }
      }

      // Descrição textual da opção.
      if (!empty($option['description'])) {
        $parts[] = '<span class="sv-option-desc">' . htmlspecialchars($option['description'], ENT_QUOTES, 'UTF-8') . '</span>';
      }

      if (!empty($parts)) {
        $form['option_id'][$option['id']]['#description'] = Markup::create(implode('', $parts));
      }
    }

    if ($locked) {
      if ($voted_option_id !== NULL) {
        $notice_parts = [$this->t('Você já votou nesta enquete.')];

        if ($show_results) {
          $results_url = Url::fromRoute('simple_voting.results', ['question_id' => $question_id])->toString();
          $notice_parts[] = Markup::create(' <a href="' . htmlspecialchars($results_url, ENT_QUOTES, 'UTF-8') . '">' . $this->t('Ver resultados') . '</a>');
        }

        $form['voted_notice'] = [
          '#type'       => 'html_tag',
          '#tag'        => 'p',
          '#value'      => Markup::create(implode('', array_map('strval', $notice_parts))),
          '#attributes' => ['class' => ['messages', 'messages--status', 'sv-voted-notice']],
          '#weight'     => 10,
        ];
      }
      else {
        // Votação global desabilitada.
        $form['locked_notice'] = [
          '#type'       => 'html_tag',
          '#tag'        => 'p',
          '#value'      => $this->t('A votação está temporariamente desabilitada.'),
          '#attributes' => ['class' => ['messages', 'messages--warning']],
          '#weight'     => 10,
        ];
      }
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

    try {
      $this->votingService->castVote($question_id, $option_id, $uid);
    }
    catch (DuplicateVoteException) {
      $this->messenger()->addWarning($this->t('Seu voto já havia sido computado anteriormente.'));
      return;
    }
    catch (VoteLockUnavailableException) {
      // Raro em uso normal. O formulário permanece disponível
      // para nova tentativa.
      $this->messenger()->addError($this->t(
        'Não foi possível registrar o voto agora. Tente novamente em instantes.'
      ));
      return;
    }

    $form_state->setRedirect('simple_voting.results', ['question_id' => $question_id]);
  }

  /**
   * Carrega as opções da enquete ordenadas por peso.
   */
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
