<?php

namespace Drupal\simple_voting\Entity;

use Drupal\Core\Config\Entity\ConfigEntityInterface;

/**
 * Interface para a entidade de configuração VotingQuestion.
 *
 * Estende ConfigEntityInterface para garantir compatibilidade com o sistema
 * de config do Drupal (export/import via CMI, cacheabilidade por config tag).
 */
interface VotingQuestionInterface extends ConfigEntityInterface {

  /**
   * Retorna TRUE se a enquete está aberta para receber votos.
   */
  public function isOpen(): bool;

  /**
   * Abre a enquete para votação.
   */
  public function open(): static;

  /**
   * Fecha a enquete, impedindo novos votos.
   */
  public function close(): static;

  /**
   * Retorna TRUE se o total de votos deve ser exibido após a votação.
   */
  public function showsResults(): bool;

  /**
   * Retorna o timestamp Unix de criação da questão.
   */
  public function getCreatedTime(): int;

  /**
   * Retorna o timestamp Unix da última modificação.
   */
  public function getChangedTime(): int;

  /**
   * Define o timestamp Unix da última modificação.
   */
  public function setChangedTime(int $timestamp): static;

}
