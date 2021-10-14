<?php

namespace Drupal\stockref_field\Plugin\Field;

use Drupal\Core\Entity\FieldableEntityInterface;
use Drupal\Core\Form\FormStateInterface;
use Drupal\chado_entity\Entity\ChadoEntity;
use Drupal\Core\Field\FieldItemList;
use Drupal\Core\Field\FieldDefinitionInterface;

/**
 * Defines an item list class for entity reference fields.
 */
class StockReferenceFieldItemList extends FieldItemList implements StockReferenceFieldItemListInterface {

  /**
   * {@inheritdoc}
   */
  public function getConstraints() {
    $constraints = parent::getConstraints();
    $constraint_manager = $this->getTypedDataManager()->getValidationConstraintManager();
    $constraints[] = $constraint_manager->create('ValidReference', []);
    return $constraints;
  }

  /**
   * {@inheritdoc}
   */
  public function referencedStocks() {
    if ($this->isEmpty()) {
      return [];
    }

    // Collect the IDs of existing entities to load, and directly grab the
    // "autocreate" entities that are already populated in $item->entity.
    $target_entities = $ids = [];
    foreach ($this->list as $delta => $item) {
      if ($item->object_id !== NULL) {
        $ids[$delta] = $item->object_id;
      }
      elseif ($item->hasNewEntity()) {
        $target_entities[$delta] = $item->entity;
      }
    }

    // Load and add the existing entities.
    if ($ids) {
      $target_type = $this->getFieldDefinition()->getSetting('target_type');
      $entities = \Drupal::entityTypeManager()->getStorage($target_type)->loadMultiple($ids);
      foreach ($ids as $delta => $object_id) {
        if (isset($entities[$object_id])) {
          $target_entities[$delta] = $entities[$object_id];
        }
      }
      // Ensure the returned array is ordered by deltas.
      ksort($target_entities);
    }

    return $target_entities;
  }

  /**
   * {@inheritdoc}
   */
  public function referencedEntities() {
    if ($this->isEmpty()) {
      return [];
    }

    // Collect the IDs of existing entities to load, and directly grab the
    // "autocreate" entities that are already populated in $item->entity.
    $target_entities = $ids = [];
    foreach ($this->list as $delta => $item) {
      if ($item->object_id !== NULL) {
        $ids[$delta] = $item->object_id;
      }
      elseif ($item->hasNewEntity()) {
        $target_entities[$delta] = $item->entity;
      }
    }

    // Load and add the existing entities.
    if ($ids) {
      $target_type = $this->getFieldDefinition()->getSetting('target_type');
      $entities = \Drupal::entityTypeManager()->getStorage($target_type)->loadMultiple($ids);
      foreach ($ids as $delta => $object_id) {
        if (isset($entities[$object_id])) {
          $target_entities[$delta] = $entities[$object_id];
        }
      }
      // Ensure the returned array is ordered by deltas.
      ksort($target_entities);
    }

    return $target_entities;
  }

  /**
   * {@inheritdoc}
   */
  public static function processDefaultValue($default_value, FieldableEntityInterface $entity, FieldDefinitionInterface $definition) {
    $default_value = parent::processDefaultValue($default_value, $entity, $definition);

    if ($default_value) {
      // Convert UUIDs to numeric IDs.
      $uuids = [];
      foreach ($default_value as $delta => $properties) {
        if (isset($properties['target_uuid'])) {
          $uuids[$delta] = $properties['target_uuid'];
        }
      }
      if ($uuids) {
        $target_type = $definition->getSetting('target_type');
        $entity_ids = \Drupal::entityQuery($target_type)
          ->accessCheck(TRUE)
          ->condition('uuid', $uuids, 'IN')
          ->execute();
        $entities = \Drupal::entityTypeManager()
          ->getStorage($target_type)
          ->loadMultiple($entity_ids);

        $entity_uuids = [];
        foreach ($entities as $id => $entity) {
          $entity_uuids[$entity->uuid()] = $id;
        }
        foreach ($uuids as $delta => $uuid) {
          if (isset($entity_uuids[$uuid])) {
            $default_value[$delta]['object_id'] = $entity_uuids[$uuid];
            unset($default_value[$delta]['target_uuid']);
          }
          else {
            unset($default_value[$delta]);
          }
        }
      }

      // Ensure we return consecutive deltas, in case we removed unknown UUIDs.
      $default_value = array_values($default_value);
    }
    return $default_value;
  }

  /**
   * {@inheritdoc}
   */
  public function defaultValuesFormSubmit(array $element, array &$form, FormStateInterface $form_state) {
    $default_value = parent::defaultValuesFormSubmit($element, $form, $form_state);

    // Convert numeric IDs to UUIDs to ensure config deployability.
    $ids = [];
    foreach ($default_value as $delta => $properties) {
      if (isset($properties['entity']) && $properties['entity']->isNew()) {
        // This may be a newly created term.
        $properties['entity']->save();
        $default_value[$delta]['object_id'] = $properties['entity']->id();
        unset($default_value[$delta]['entity']);
      }
      $ids[] = $default_value[$delta]['object_id'];
    }
    $entities = \Drupal::entityTypeManager()
      ->getStorage($this->getSetting('target_type'))
      ->loadMultiple($ids);

    foreach ($default_value as $delta => $properties) {
      unset($default_value[$delta]['object_id']);
      $default_value[$delta]['target_uuid'] = $entities[$properties['object_id']]->uuid();
    }
    return $default_value;
  }

}
