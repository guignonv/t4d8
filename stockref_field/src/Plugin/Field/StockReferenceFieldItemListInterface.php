<?php

namespace Drupal\stockref_field\Plugin\Field;

use Drupal\Core\Field\FieldItemListInterface;

/**
 * Interface for stock reference lists of field items.
 */
interface StockReferenceFieldItemListInterface extends FieldItemListInterface {

  /**
   * Gets the stocks referenced by this field, preserving field item deltas.
   *
   * @return \Drupal\chado_entity\Entity\ChadoEntity[]
   *   An array of entity objects keyed by field item deltas.
   */
  public function referencedStocks();

}
