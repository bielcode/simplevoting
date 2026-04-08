<?php

namespace Drupal\simple_voting\Breadcrumb;

use Drupal\Core\Breadcrumb\Breadcrumb;
use Drupal\Core\Breadcrumb\BreadcrumbBuilderInterface;
use Drupal\Core\Entity\EntityTypeManagerInterface;
use Drupal\Core\Link;
use Drupal\Core\Routing\RouteMatchInterface;
use Drupal\Core\StringTranslation\StringTranslationTrait;

/**
 * Constrói o breadcrumb para as rotas do módulo Simple Voting.
 *
 * Hierarquia de rotas:
 *   Home > Enquetes > {Título da enquete} > Resultado.
 *
 *   /voting                          → Home > Enquetes
 *   /voting/{question_id}            → Home > Enquetes > {Título da enquete}
 *   /simple-voting/results/{id}    → Home > Enquetes > {Título} > Resultado
 */
class VotingBreadcrumbBuilder implements BreadcrumbBuilderInterface {

  use StringTranslationTrait;

  /**
   * Rotas cobertas por este builder.
   */
  private const ROUTES = [
    'simple_voting.questions_list',
    'simple_voting.vote_page',
    'simple_voting.results',
  ];

  public function __construct(
    protected readonly EntityTypeManagerInterface $entityTypeManager,
  ) {}

  /**
   * {@inheritdoc}
   */
  public function applies(RouteMatchInterface $route_match): bool {
    return in_array($route_match->getRouteName(), self::ROUTES, TRUE);
  }

  /**
   * {@inheritdoc}
   */
  public function build(RouteMatchInterface $route_match): Breadcrumb {
    $breadcrumb = new Breadcrumb();
    $breadcrumb->addCacheContexts(['route']);

    $links = [Link::createFromRoute($this->t('Home'), '<front>')];

    $route = $route_match->getRouteName();

    // Todas as rotas de votação exibem a lista de enquetes como segundo nível.
    if ($route !== 'simple_voting.questions_list') {
      $links[] = Link::createFromRoute($this->t('Enquetes'), 'simple_voting.questions_list');
    }

    // Para detalhe da enquete e resultados, carrega o título da questão.
    $question_id = $route_match->getParameter('question_id');
    if ($question_id) {
      $question = $this->entityTypeManager
        ->getStorage('voting_question')
        ->load($question_id);

      $question_label = $question ? $question->label() : $question_id;

      $breadcrumb->addCacheTags(['config:simple_voting.question.' . $question_id]);

      if ($route === 'simple_voting.results') {
        // Página de resultados: o título da enquete serve de link
        // para a página de votação.
        $links[] = Link::createFromRoute(
          $question_label,
          'simple_voting.vote_page',
          ['question_id' => $question_id]
        );
        $links[] = Link::createFromRoute($this->t('Resultado'), '<none>');
      }
      else {
        // Página de votação: o título da questão é a página atual (sem link).
        $links[] = Link::createFromRoute($question_label, '<none>');
      }
    }

    $breadcrumb->setLinks($links);

    return $breadcrumb;
  }

}
