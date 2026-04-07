<?php

namespace Drupal\simple_voting\EventSubscriber;

use Drupal\Core\Messenger\MessengerInterface;
use Drupal\Core\Routing\RedirectDestinationInterface;
use Drupal\Core\Session\AccountInterface;
use Drupal\Core\StringTranslation\StringTranslationTrait;
use Drupal\Core\Url;
use Symfony\Component\EventDispatcher\EventSubscriberInterface;
use Symfony\Component\HttpFoundation\RedirectResponse;
use Symfony\Component\HttpKernel\Event\ExceptionEvent;
use Symfony\Component\HttpKernel\Exception\AccessDeniedHttpException;
use Symfony\Component\HttpKernel\KernelEvents;

/**
 * Redireciona usuários anônimos para o login ao receberem um 403.
 *
 * Sem este subscriber, usuários anônimos que acessam /voting veriam a página
 * genérica "Access denied" do Drupal. Agora eles são redirecionados para a página de login.
 */
class VotingAccessDeniedSubscriber implements EventSubscriberInterface {

  use StringTranslationTrait;

  public function __construct(
    protected readonly AccountInterface $currentUser,
    protected readonly MessengerInterface $messenger,
    protected readonly RedirectDestinationInterface $redirectDestination,
  ) {}

  /**
   * {@inheritdoc}
   */
  public static function getSubscribedEvents(): array {
    // Prioridade 75 executa antes do handler padrão de exceções (prioridade 50), antes do render do 403.
    return [
      KernelEvents::EXCEPTION => ['onException', 75],
    ];
  }

  public function onException(ExceptionEvent $event): void {
    if (!$event->getThrowable() instanceof AccessDeniedHttpException) {
      return;
    }

    if (!$this->currentUser->isAnonymous()) {
      return;
    }

    $this->messenger->addWarning(
      $this->t('Faça login para acessar o conteúdo deste site.')
    );

    $login_url = Url::fromRoute('user.login', [], [
      'query' => $this->redirectDestination->getAsArray(),
    ])->toString();

    $event->setResponse(new RedirectResponse($login_url));
  }

}
