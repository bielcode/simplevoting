<?php

namespace Drupal\simple_voting\Entity;

use Drupal\Core\Config\Entity\ConfigEntityBase;
use Drupal\Core\Entity\EntityStorageInterface;

/**
 * Define a entidade de configuração VotingQuestion.
 *
 * Usar ConfigEntityBase é a escolha deliberada aqui: enquetes são configuração
 * de site (exportável via CMI, gerenciável por deploy), não conteúdo editorial.
 * Os votos em si são dados transacionais e ficam nas tabelas customizadas do
 * hook_schema(), essa separação mantém o modelo limpo.
 *
 * @ConfigEntityType(
 *   id = "voting_question",
 *   label = @Translation("Voting Question"),
 *   label_collection = @Translation("Voting Questions"),
 *   label_singular = @Translation("voting question"),
 *   label_plural = @Translation("voting questions"),
 *   label_count = @PluralTranslation(
 *     singular = "@count voting question",
 *     plural = "@count voting questions",
 *   ),
 *   handlers = {
 *     "storage" = "Drupal\Core\Config\Entity\ConfigEntityStorage",
 *     "list_builder" = "Drupal\simple_voting\Entity\VotingQuestionListBuilder",
 *     "form" = {
 *       "add"    = "Drupal\simple_voting\Form\QuestionForm",
 *       "edit"   = "Drupal\simple_voting\Form\QuestionForm",
 *       "delete" = "Drupal\Core\Entity\EntityDeleteForm",
 *     },
 *   },
 *   config_prefix = "question",
 *   admin_permission = "administer simple voting",
 *   entity_keys = {
 *     "id"     = "id",
 *     "label"  = "title",
 *     "uuid"   = "uuid",
 *     "status" = "status",
 *   },
 *   links = {
 *     "collection"  = "/admin/config/simple-voting/questions",
 *     "add-form"    = "/admin/config/simple-voting/questions/add",
 *     "edit-form"   = "/admin/config/simple-voting/questions/{voting_question}/edit",
 *     "delete-form" = "/admin/config/simple-voting/questions/{voting_question}/delete",
 *   },
 *   config_export = {
 *     "id",
 *     "title",
 *     "show_results",
 *     "status",
 *     "created",
 *     "changed",
 *   },
 * )
 */
class VotingQuestion extends ConfigEntityBase implements VotingQuestionInterface {

  /**
   * Machine name da questão; serve como ID único no sistema de config.
   *
   * Ex.: "eleicao_presidente_2025". Imutável após a criação para não
   * invalidar referências externas (config names, cache tags).
   */
  protected string $id;

  /**
   * Texto completo da pergunta exibida ao usuario.
   */
  protected string $title = '';

  /**
   * Quando TRUE, a contagem parcial fica visível antes do encerramento.
   */
  protected bool $show_results = FALSE;

  // $status já é declarado em ConfigEntityBase como TRUE (enabled).
  // Reaproveitamos a semântica: TRUE = enquete aberta, FALSE = encerrada.

  /**
   * Timestamp Unix do momento em que a questão foi criada.
   */
  protected int $created = 0;

  /**
   * Timestamp Unix da última modificação do registro.
   */
  protected int $changed = 0;

  // ---------------------------------------------------------------------------
  // VotingQuestionInterface
  // ---------------------------------------------------------------------------

  /**
   * {@inheritdoc}
   */
  public function isOpen(): bool {
    return (bool) $this->status;
  }

  /**
   * {@inheritdoc}
   */
  public function open(): static {
    $this->status = TRUE;
    return $this;
  }

  /**
   * {@inheritdoc}
   */
  public function close(): static {
    $this->status = FALSE;
    return $this;
  }

  /**
   * {@inheritdoc}
   */
  public function showsResults(): bool {
    return $this->show_results;
  }

  /**
   * {@inheritdoc}
   */
  public function getCreatedTime(): int {
    return $this->created;
  }

  /**
   * {@inheritdoc}
   */
  public function getChangedTime(): int {
    return $this->changed;
  }

  /**
   * {@inheritdoc}
   */
  public function setChangedTime(int $timestamp): static {
    $this->changed = $timestamp;
    return $this;
  }

  /**
   * {@inheritdoc}
   */
  public function preSave(EntityStorageInterface $storage): void {
    parent::preSave($storage);

    $now = \Drupal::time()->getRequestTime();

    if ($this->isNew()) {
      $this->created = $now;
    }

    $this->changed = $now;
  }

}
