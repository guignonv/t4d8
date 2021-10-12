<?php

namespace Drupal\chado_entity\Entity;

use Drupal\Core\Entity\EntityStorageInterface;
use Drupal\Core\Field\BaseFieldDefinition;
use Drupal\Core\Entity\ContentEntityBase;
use Drupal\Core\Entity\EntityTypeInterface;
use Drupal\chado_entity\ChadoEntityInterface;

/**
 * Defines the ChadoEntity entity.
 *
 * @ingroup chado_entity
 *
 * @ContentEntityType(
 *   id = "chado_entity",
 *   label = @Translation("Chado entity"),
 *   handlers = {
 *     "view_builder" = "Drupal\Core\Entity\EntityViewBuilder",
 *     "views_data" = "Drupal\chado_entity\Entity\ChadoEntityViewsData",
 *     "list_builder" = "Drupal\chado_entity\Entity\Controller\ChadoEntityListBuilder",
 *     "storage" = "Drupal\chado_entity\Storage\ChadoEntityStorage",
 *     "storage_schema" = "Drupal\chado_entity\Storage\ChadoEntityStorageSchema",
 *     "form" = {
 *       "default" = "Drupal\chado_entity\Form\ChadoEntityForm",
 *       "add" = "Drupal\chado_entity\Form\ChadoEntityForm",
 *       "edit" = "Drupal\chado_entity\Form\ChadoEntityForm",
 *       "delete" = "Drupal\chado_entity\Form\ChadoEntityDeleteForm",
 *     },
 *     "access" = "Drupal\chado_entity\ChadoEntityAccessControlHandler",
 *   },
 *   list_cache_contexts = { "user" },
 *   base_table = "stock",
 *   admin_permission = "administer chado entity",
 *   entity_keys = {
 *     "id" = "stock_id",
 *     "label" = "name",
 *     "uuid" = "uniquename",
 *   },
 *   links = {
 *     "canonical" = "/stock/{chado_entity}",
 *     "edit-form" = "/stock/{chado_entity}/edit",
 *     "delete-form" = "/stock/{chado_entity}/delete",
 *     "collection" = "/stock/list",
 *   },
 *   field_ui_base_route = "chado_entity.chado_entity_settings",
 * )
 *
 * The Chado entity class defines methods and fields for the Chado entity.
 *
 */
class ChadoEntity extends ContentEntityBase implements ChadoEntityInterface {

  /**
   * {@inheritdoc}
   *
   * When a new entity instance is added, set the user_id entity reference to
   * the current user as the creator of the instance.
   */
  // public static function preCreate(EntityStorageInterface $storage_controller, array &$values) {
  //   parent::preCreate($storage_controller, $values);
  //   $values += [
  //     'user_id' => \Drupal::currentUser()->id(),
  //   ];
  // }

  /**
   * {@inheritdoc}
   *
   */
  public static function baseFieldDefinitions(EntityTypeInterface $entity_type) {

    // Standard field, used as unique if primary index.
    $fields['stock_id'] = BaseFieldDefinition::create('integer')
      ->setLabel(t('ID'))
      ->setDescription(t('The ID of the Stock entity.'))
      ->setReadOnly(TRUE);

    // Standard field, unique outside of the scope of the current project.
    $fields['uniquename'] = BaseFieldDefinition::create('string')
      ->setLabel(t('Uniquename'))
      ->setDescription(t('The uniquename of the Stock entity.'))
      ->setSettings([
        'max_length' => 255,
        'text_processing' => 0,
      ])
      // Set no default value.
      ->setDefaultValue('')
      ->setDisplayOptions('view', [
        'label' => 'above',
        'type' => 'string',
        'weight' => -6,
      ])
      ->setDisplayOptions('form', [
        'type' => 'string_textfield',
        'weight' => -6,
      ])
      ->setDisplayConfigurable('form', TRUE)
      ->setDisplayConfigurable('view', TRUE);

    // Name field for the stock.
    // We set display options for the view as well as the form.
    // Users with correct privileges can change the view and edit configuration.
    $fields['name'] = BaseFieldDefinition::create('string')
      ->setLabel(t('Name'))
      ->setDescription(t('The name of the Stock entity.'))
      ->setSettings([
        'max_length' => 255,
        'text_processing' => 0,
      ])
      // Set no default value.
      ->setDefaultValue('')
      ->setDisplayOptions('view', [
        'label' => 'above',
        'type' => 'string',
        'weight' => -6,
      ])
      ->setDisplayOptions('form', [
        'type' => 'string_textfield',
        'weight' => -6,
      ])
      ->setDisplayConfigurable('form', TRUE)
      ->setDisplayConfigurable('view', TRUE);

    $fields['description'] = BaseFieldDefinition::create('string')
      ->setLabel(t('Description'))
      ->setDescription(t('Stock description.'))
      ->setSettings([
        'max_length' => 4096,
        'text_processing' => 0,
      ])
      // Set no default value.
      ->setDefaultValue('')
      ->setDisplayOptions('view', [
        'label' => 'above',
        'type' => 'string',
        'weight' => -5,
      ])
      ->setDisplayOptions('form', [
        'type' => 'string_textfield',
        'weight' => -5,
      ])
      ->setDisplayConfigurable('form', TRUE)
      ->setDisplayConfigurable('view', TRUE);

    $fields['type_id'] = BaseFieldDefinition::create('integer')
      ->setLabel(t('Type ID'))
      ->setDescription(t('Stock type.'))
      // Set no default value.
      ->setDefaultValue(1)
      ->setDisplayOptions('view', [
        'label' => 'above',
        'type' => 'string',
        'weight' => -5,
      ])
      ->setDisplayOptions('form', [
        'type' => 'string_textfield',
        'weight' => -5,
      ])
      ->setDisplayConfigurable('form', TRUE)
      ->setDisplayConfigurable('view', TRUE);

    return $fields;
  }

  /**
   * {@inheritdoc}
   */
  // public function getEntityType() {
  //   return parent::getEntityType();
  //   // $this
  //   //   ->entityTypeManager()
  //   //   ->getDefinition($this->getEntityTypeId());
  // }
}
