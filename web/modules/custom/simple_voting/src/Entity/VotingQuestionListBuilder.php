<?php

namespace Drupal\simple_voting\Entity;

use Drupal\Core\Config\Entity\ConfigEntityListBuilder;
use Drupal\Core\Datetime\DateFormatterInterface;
use Drupal\Core\Entity\EntityInterface;
use Drupal\Core\Entity\EntityStorageInterface;
use Drupal\Core\Entity\EntityTypeInterface;
use Symfony\Component\DependencyInjection\ContainerInterface;

/**
 * Lista administrativa de enquetes (VotingQuestion).
 *
 * Renderiza a tabela em /admin/config/simple-voting/questions.
 */
class VotingQuestionListBuilder extends ConfigEntityListBuilder {

  public function __construct(
    EntityTypeInterface $entity_type,
    EntityStorageInterface $storage,
    protected readonly DateFormatterInterface $dateFormatter,
  ) {
    parent::__construct($entity_type, $storage);
  }

  /**
   * {@inheritdoc}
   */
  public static function createInstance(ContainerInterface $container, EntityTypeInterface $entity_type): static {
    return new static(
      $entity_type,
      $container->get('entity_type.manager')->getStorage($entity_type->id()),
      $container->get('date.formatter'),
    );
  }

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
      ? $this->dateFormatter->format($entity->getCreatedTime(), 'short')
      : '—';
    return $row + parent::buildRow($entity);
  }

}
