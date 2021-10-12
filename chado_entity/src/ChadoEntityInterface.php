<?php

namespace Drupal\chado_entity;

use Drupal\Core\Entity\FieldableEntityInterface;
// use Drupal\Core\Entity\TranslatableRevisionableInterface;
use Drupal\Core\Entity\SynchronizableInterface;

/**
 * Provides an interface defining a Chado entity.
 *
 * We don't extend ContentEntityInterface because we don't want to manage
 * TranslatableRevisionableInterface interface.
 *
 * @ingroup chado_entity
 */
interface ChadoEntityInterface extends
  \Traversable,
  FieldableEntityInterface,
  /*TranslatableRevisionableInterface,*/
  SynchronizableInterface {
}
