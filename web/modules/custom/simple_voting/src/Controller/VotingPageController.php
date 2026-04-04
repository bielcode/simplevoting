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
use Symfony\Component\HttpKernel\Exception\NotFoundHttpException;

/**
 * Páginas públicas de listagem e votação para usuários finais.
 */
class VotingPageController extends ControllerBase {

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
   * GET /voting
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
        'contexts' => ['user.permissions'],
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
      ->condition('status', TRUE)
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

    $items = [];
    foreach ($questions as $question) {
      $items[] = [
        '#type'  => 'link',
        '#title' => $question->label(),
        '#url'   => Url::fromRoute('simple_voting.vote_page', ['question_id' => $question->id()]),
      ];
    }

    $build['list'] = [
      '#theme' => 'item_list',
      '#title' => $this->t('Enquetes disponíveis'),
      '#items' => $items,
    ];

    return $build;
  }

  /**
   * GET /voting/{question_id}
   *
   * Exibe o formulário de votação para a enquete informada.
   */
  public function votePage(string $question_id): array {
    $question = $this->entityTypeManager()
      ->getStorage('voting_question')
      ->load($question_id);

    if ($question === NULL || !$question->isOpen()) {
      throw new NotFoundHttpException();
    }

    $voting_enabled = (bool) $this->configFactory
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

    $locked = !$voting_enabled || $already_voted;

    return $this->formBuilder->getForm(VoteForm::class, $question_id, $locked);
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
