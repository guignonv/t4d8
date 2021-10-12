<?php

namespace Drupal\chado_entity\Entity;

use Drupal\views\EntityViewsData;

/**
 * Provides Views data for Default entity entities.
 */
class ChadoEntityViewsData extends EntityViewsData {

  /**
   * {@inheritdoc}
   */
  public function getViewsData() {
    $data = parent::getViewsData();
    //$data['default_entity']['table']['base']['database'] = 'second_db';

    return $data;
  }

}