<?php

namespace Drupal\simple_voting\EventSubscriber;

use Symfony\Component\EventDispatcher\EventSubscriberInterface;
use Symfony\Component\HttpFoundation\JsonResponse;
use Symfony\Component\HttpKernel\Event\ExceptionEvent;
use Symfony\Component\HttpKernel\Exception\HttpExceptionInterface;
use Symfony\Component\HttpKernel\KernelEvents;

/**
 * Converte exceções HTTP em respostas JSON para rotas da API de votação.
 *
 * Sem este subscriber, exceções como UnprocessableEntityHttpException e
 * ConflictHttpException lançadas pelo VotingApiController fariam o Drupal
 * renderizar a página de erro padrão em HTML — inútil para clientes API.
 *
 * O subscriber intercepta apenas requisições cujo path começa com
 * /api/voting/, garantindo que o comportamento do restante do site não seja
 * afetado.
 */
class VotingApiExceptionSubscriber implements EventSubscriberInterface {

  /**
   * {@inheritdoc}
   */
  public static function getSubscribedEvents(): array {
    // Prioridade 200 executa antes do VotingAccessDeniedSubscriber (75) e
    // antes do handler padrão de exceções do Drupal (50), garantindo que
    // as rotas da API sempre recebam JSON.
    return [
      KernelEvents::EXCEPTION => ['onException', 200],
    ];
  }

  /**
   * Converte HttpException em JsonResponse para paths /api/voting/*.
   */
  public function onException(ExceptionEvent $event): void {
    $request   = $event->getRequest();
    $throwable = $event->getThrowable();

    if (!str_starts_with($request->getPathInfo(), '/api/voting/')) {
      return;
    }

    if (!$throwable instanceof HttpExceptionInterface) {
      return;
    }

    $response = new JsonResponse(
      ['error' => $throwable->getMessage()],
      $throwable->getStatusCode(),
      $throwable->getHeaders(),
    );

    $event->setResponse($response);
  }

}
