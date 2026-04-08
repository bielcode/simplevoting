<?php

namespace Drupal\simple_voting\Controller;

use Drupal\Core\Config\ConfigFactoryInterface;
use Drupal\Core\Controller\ControllerBase;
use Drupal\Core\Database\Connection;
use Drupal\Core\Form\FormBuilderInterface;
use Drupal\Core\Session\AccountProxyInterface;
use Drupal\Core\StringTranslation\TranslatableMarkup;
use Drupal\Core\Url;
use Drupal\simple_voting\Form\VoteForm;
use Symfony\Component\DependencyInjection\ContainerInterface;
use Symfony\Component\HttpFoundation\RedirectResponse;
use Symfony\Component\HttpKernel\Exception\NotFoundHttpException;

/**
 * Páginas públicas de listagem e votação para usuários finais.
 */
class VotingPageController extends ControllerBase {

  /**
   * The database connection.
   *
   * @var \Drupal\Core\Database\Connection
   */
  protected Connection $database;

  public function __construct(
    ConfigFactoryInterface $configFactory,
    AccountProxyInterface $currentUser,
    FormBuilderInterface $formBuilder,
    Connection $database,
  ) {
    $this->configFactory = $configFactory;
    $this->currentUser   = $currentUser;
    $this->formBuilder   = $formBuilder;
    $this->database      = $database;
  }

  /**
   * {@inheritdoc}
   */
  public static function create(ContainerInterface $container): static {
    return new static(
      $container->get('config.factory'),
      $container->get('current_user'),
      $container->get('form_builder'),
      $container->get('database'),
    );
  }

  /**
   * GET /voting.
   *
   * Lista as enquetes abertas com links para votação.
   * Quando voting_enabled = FALSE, exibe aviso e nenhum link.
   */
  public function listQuestions(): array {
    $voting_enabled = (bool) $this->configFactory
      ->get('simple_voting.settings')
      ->get('voting_enabled');

    $build = [
      '#cache' => [
        'tags'     => ['voting_question_list', 'config:simple_voting.settings'],
        'contexts' => ['user.permissions', 'user'],
      ],
    ];

    if (!$voting_enabled) {
      $build['message'] = [
        '#type'       => 'html_tag',
        '#tag'        => 'p',
        '#value'      => $this->t('As votações estão temporariamente desabilitadas.'),
        '#attributes' => ['class' => ['messages', 'messages--warning']],
      ];
      return $build;
    }

    $storage = $this->entityTypeManager()->getStorage('voting_question');
    $ids = $storage->getQuery()
      ->accessCheck(FALSE)
      ->execute();

    /** @var \Drupal\simple_voting\Entity\VotingQuestionInterface[] $questions */
    $questions = $ids ? $storage->loadMultiple($ids) : [];

    if (empty($questions)) {
      $build['empty'] = [
        '#type'  => 'html_tag',
        '#tag'   => 'p',
        '#value' => $this->t('Nenhuma enquete disponível no momento.'),
      ];
      return $build;
    }

    foreach ($questions as $question) {
      $build['#cache']['tags'][] = 'simple_voting:question:' . $question->id();
    }

    $uid = (int) $this->currentUser->id();
    $voted_ids = [];
    if ($uid) {
      $voted_ids = array_flip($this->database
        ->select('simple_voting_vote', 'v')
        ->fields('v', ['question_id'])
        ->condition('v.uid', $uid)
        ->execute()
        ->fetchCol());
    }

    $items = [];
    foreach ($questions as $question) {
      $qid        = $question->id();
      $user_voted = isset($voted_ids[$qid]);

      if (!$question->isOpen()) {
        if ($question->showsResults()) {
          $items[] = [
            '#type'       => 'link',
            '#title'      => $question->label() . ' (' . $this->t('Encerrada') . ')',
            '#url'        => Url::fromRoute('simple_voting.results', ['question_id' => $qid]),
            '#attributes' => ['class' => ['sv-question--closed']],
          ];
        }
        else {
          $items[] = [
            '#markup' => '<span class="sv-question--closed">' . $this->t('@label (Encerrada)', ['@label' => $question->label()]) . '</span>',
          ];
        }
      }
      elseif ($user_voted) {
        $url = $question->showsResults()
          ? Url::fromRoute('simple_voting.results', ['question_id' => $qid])
          : Url::fromRoute('simple_voting.vote_page', ['question_id' => $qid]);

        $items[] = [
          '#type'       => 'link',
          '#title'      => $question->label() . ' (' . $this->t('Votado') . ')',
          '#url'        => $url,
          '#attributes' => ['class' => ['sv-question--voted']],
        ];
      }
      else {
        $items[] = [
          '#type'  => 'link',
          '#title' => $question->label(),
          '#url'   => Url::fromRoute('simple_voting.vote_page', ['question_id' => $qid]),
        ];
      }
    }

    $build['list'] = [
      '#theme' => 'item_list',
      '#items' => $items,
    ];

    return $build;
  }

  /**
   * GET /voting/{question_id}.
   *
   * Exibe o formulário de votação para a enquete informada.
   */
  public function votePage(string $question_id): array|RedirectResponse {
    $question = $this->entityTypeManager()
      ->getStorage('voting_question')
      ->load($question_id);

    if ($question === NULL) {
      throw new NotFoundHttpException();
    }

    if (!$question->isOpen()) {
      if ($question->showsResults()) {
        return new RedirectResponse(
          Url::fromRoute('simple_voting.results', ['question_id' => $question_id])->toString()
        );
      }
      return [
        'message' => [
          '#type'       => 'html_tag',
          '#tag'        => 'p',
          '#value'      => $this->t('Esta enquete está encerrada.'),
          '#attributes' => ['class' => ['messages', 'messages--warning']],
        ],
        '#cache' => [
          'tags' => ['config:simple_voting.question.' . $question_id],
        ],
      ];
    }

    $voting_enabled = (bool) $this->configFactory
      ->get('simple_voting.settings')
      ->get('voting_enabled');

    $uid = (int) $this->currentUser->id();

    $voted_option_id = $this->database
      ->select('simple_voting_vote', 'v')
      ->fields('v', ['option_id'])
      ->condition('v.question_id', $question_id)
      ->condition('v.uid', $uid)
      ->range(0, 1)
      ->execute()
      ->fetchField();

    $already_voted = $voted_option_id !== FALSE;

    $locked = !$voting_enabled || $already_voted;

    return $this->formBuilder->getForm(
      VoteForm::class,
      $question_id,
      $locked,
      $already_voted ? (int) $voted_option_id : NULL,
      $question->showsResults(),
    );
  }

  /**
   * Título dinâmico para a rota /voting/{question_id}.
   */
  public function votePageTitle(string $question_id): TranslatableMarkup|string {
    $question = $this->entityTypeManager()
      ->getStorage('voting_question')
      ->load($question_id);

    return $question ? $question->label() : $this->t('Votação');
  }

}
