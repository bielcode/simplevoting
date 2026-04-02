<?php

namespace Drupal\simple_voting\Exception;

/**
 * Lançada quando o usuário já possui um voto registrado para a enquete.
 *
 * Separar em exception própria permite que controller e form tratem a situação
 * de formas distintas (HTTP 409 vs. mensagem de aviso no form) sem precisar
 * inspecionar mensagens de string ou códigos numéricos.
 */
class DuplicateVoteException extends \RuntimeException {

  public function __construct() {
    parent::__construct('O usuário já votou nesta enquete.');
  }

}
