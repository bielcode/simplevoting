<?php

namespace Drupal\simple_voting\Entity;

use Drupal\Core\Config\Entity\ConfigEntityListBuilder;
use Drupal\Core\Entity\EntityInterface;

/**
 * Lista administrativa de enquetes (VotingQuestion).
 *
 * Renderiza a tabela em /admin/config/simple-voting/questions.
 */
class VotingQuestionListBuilder extends ConfigEntityListBuilder {

  /**
   * {@inheritdoc}
   */
  public function buildHeader(): array {
    $header['title']   = $this->t('Título');
    $header['id']      = $this->t('Identificador');
    $header['status']  = $this->t('Status');
    $header['created'] = $this->t('Criado em');
    return $header + parent::buildHeader();
  }

  /**
   * {@inheritdoc}
   */
  public function buildRow(EntityInterface $entity): array {
    /** @var \Drupal\simple_voting\Entity\VotingQuestion $entity */
    $row['title']   = $entity->label();
    $row['id']      = $entity->id();
    $row['status']  = $entity->isOpen()
      ? $this->t('Aberta')
      : $this->t('Encerrada');
    $row['created'] = $entity->getCreatedTime()
      ? \Drupal::service('date.formatter')->format($entity->getCreatedTime(), 'short')
      : '—';
    return $row + parent::buildRow($entity);
  }

}
