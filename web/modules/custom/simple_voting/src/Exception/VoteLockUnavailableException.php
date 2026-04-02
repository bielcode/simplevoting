<?php

namespace Drupal\simple_voting\Exception;

/**
 * Lançada quando o lock de votação não pode ser adquirido.
 *
 * Em condições normais isso é transitório — o lock é liberado em milissegundos.
 * Se esta exception aparecer com frequência nos logs, indica alto volume
 * simultâneo de votos para o mesmo par (uid, question) ou um processo morto
 * que não liberou o lock antes de expirar o TTL.
 */
class VoteLockUnavailableException extends \RuntimeException {}
