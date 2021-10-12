<?php

namespace Drupal\chado_entity;

use Drupal\node\NodeStorage as CoreNodeStorage;
use Drupal\Core\Entity\EntityTypeInterface;
use Symfony\Component\DependencyInjection\ContainerInterface;

/**
 * Defines the storage handler class for nodes.
 *
 * This extends the base storage class, adding required special handling for
 * node entities.
 */
class NodeStorage extends CoreNodeStorage
{
  public static function createInstance(ContainerInterface $container, EntityTypeInterface $entityType)
  {
    return new static(
      $entityType,
      $container->get('tripal_chado.database'),
      $container->get('entity.manager'),
      $container->get('cache.entity'),
      $container->get('language_manager'),
      $container->get('entity.memory_cache')
    );
  }
}