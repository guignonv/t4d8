<?php

namespace Drupal\chado_entity\Entity\Sql;

use Drupal\Core\Entity\ContentEntityTypeInterface;
use Drupal\Core\Entity\EntityTypeInterface;
use Drupal\Core\Field\FieldStorageDefinitionInterface;
use Drupal\Core\Entity\Sql\DefaultTableMapping;
use Drupal\Core\Entity\Sql\TableMappingInterface;

/**
 * Defines a default table mapping class.
 */
class ChadoTableMapping extends DefaultTableMapping {

 /**
  *
  */
  public function getDedicatedDataTableName(FieldStorageDefinitionInterface $storage_definition, $is_deleted = FALSE) {
    $table_name = parent::getDedicatedDataTableName($storage_definition, $is_deleted);
    \Drupal::messenger()->addMessage("DEBUG ChadoTableMapping::getDedicatedDataTableName $table_name"); //+debug
    return $table_name;
  }

  /**
   *
   */
  protected function generateFieldTableName(FieldStorageDefinitionInterface $storage_definition, $revision) {
    $table_name = parent::generateFieldTableName($storage_definition, $revision);
    \Drupal::messenger()->addMessage("DEBUG ChadoTableMapping::generateFieldTableName $table_name"); //+debug
    return $table_name;
  }
  
/**
   * Initializes the table mapping.
   *
   * @param \Drupal\Core\Entity\ContentEntityTypeInterface $entity_type
   *   The entity type definition.
   * @param \Drupal\Core\Field\FieldStorageDefinitionInterface[] $storage_definitions
   *   A list of field storage definitions that should be available for the
   *   field columns of this table mapping.
   * @param string $prefix
   *   (optional) A prefix to be used by all the tables of this mapping.
   *   Defaults to an empty string.
   *
   * @return static
   *
   * @internal
   */
  public static function create(ContentEntityTypeInterface $entity_type, array $storage_definitions, $prefix = '') {
    // $table_mapping = parent::create($entity_type, $storage_definitions, $prefix);
    // return $table_mapping;
    \Drupal::messenger()->addMessage("DEBUG ChadoTableMapping::create"); //+debug
    $table_mapping = new static($entity_type, $storage_definitions, $prefix);
    $revisionable = $entity_type
      ->isRevisionable();
    $translatable = $entity_type
      ->isTranslatable();
    $id_key = $entity_type
      ->getKey('id');
    $revision_key = $entity_type
      ->getKey('revision');
    $bundle_key = $entity_type
      ->getKey('bundle');
    $uuid_key = $entity_type
      ->getKey('uuid');
    $langcode_key = $entity_type
      ->getKey('langcode');
    $shared_table_definitions = array_filter($storage_definitions, function (FieldStorageDefinitionInterface $definition) use ($table_mapping) {
      return $table_mapping
        ->allowsSharedTableStorage($definition);
    });
    $key_fields = array_values(array_filter([
      $id_key,
      $revision_key,
      $bundle_key,
      $uuid_key,
      $langcode_key,
    ]));
    $all_fields = array_keys($shared_table_definitions);
    $revisionable_fields = array_keys(array_filter($shared_table_definitions, function (FieldStorageDefinitionInterface $definition) {
      return $definition
        ->isRevisionable();
    }));

    // Make sure the key fields come first in the list of fields.
    $all_fields = array_merge($key_fields, array_diff($all_fields, $key_fields));
    $revision_metadata_fields = $revisionable ? array_values($entity_type
      ->getRevisionMetadataKeys()) : [];
    $revision_metadata_fields = array_intersect($revision_metadata_fields, array_keys($storage_definitions));
    if (!$revisionable && !$translatable) {

      // The base layout stores all the base field values in the base table.
      $table_mapping->setFieldNames($table_mapping->baseTable, $all_fields);
    }
    elseif ($revisionable && !$translatable) {

      // The revisionable layout stores all the base field values in the base
      // table, except for revision metadata fields. Revisionable fields
      // denormalized in the base table but also stored in the revision table
      // together with the entity ID and the revision ID as identifiers.
      $table_mapping
        ->setFieldNames($table_mapping->baseTable, array_diff($all_fields, $revision_metadata_fields));
      $revision_key_fields = [
        $id_key,
        $revision_key,
      ];
      $table_mapping
        ->setFieldNames($table_mapping->revisionTable, array_merge($revision_key_fields, $revisionable_fields));
    }
    elseif (!$revisionable && $translatable) {

      // Multilingual layouts store key field values in the base table. The
      // other base field values are stored in the data table, no matter
      // whether they are translatable or not. The data table holds also a
      // denormalized copy of the bundle field value to allow for more
      // performant queries. This means that only the UUID is not stored on
      // the data table.
      $table_mapping
        ->setFieldNames($table_mapping->baseTable, $key_fields)
        ->setFieldNames($table_mapping->dataTable, array_values(array_diff($all_fields, [
        $uuid_key,
      ])));
    }
    elseif ($revisionable && $translatable) {

      // The revisionable multilingual layout stores key field values in the
      // base table and the revision table holds the entity ID, revision ID and
      // langcode ID along with revision metadata. The revision data table holds
      // data field values for all the revisionable fields and the data table
      // holds the data field values for all non-revisionable fields. The data
      // field values of revisionable fields are denormalized in the data
      // table, as well.
      $table_mapping
        ->setFieldNames($table_mapping->baseTable, $key_fields);

      // Like in the multilingual, non-revisionable case the UUID is not
      // in the data table. Additionally, do not store revision metadata
      // fields in the data table.
      $data_fields = array_values(array_diff($all_fields, [
        $uuid_key,
      ], $revision_metadata_fields));
      $table_mapping
        ->setFieldNames($table_mapping->dataTable, $data_fields);
      $revision_base_fields = array_merge([
        $id_key,
        $revision_key,
        $langcode_key,
      ], $revision_metadata_fields);
      $table_mapping
        ->setFieldNames($table_mapping->revisionTable, $revision_base_fields);
      $revision_data_key_fields = [
        $id_key,
        $revision_key,
        $langcode_key,
      ];
      $revision_data_fields = array_diff($revisionable_fields, $revision_metadata_fields, [
        $langcode_key,
      ]);
      $table_mapping
        ->setFieldNames($table_mapping->revisionDataTable, array_merge($revision_data_key_fields, $revision_data_fields));
    }

    // Add dedicated tables.
    $dedicated_table_definitions = array_filter(
      $table_mapping->fieldStorageDefinitions,
      function (FieldStorageDefinitionInterface $definition) use ($table_mapping) {
        return $table_mapping->requiresDedicatedTableStorage($definition);
      }
    );
    $extra_columns = [
//      'bundle',
//      'deleted',
//      'entity_id',
//      'revision_id',
//      'langcode',
//      'delta',
      'stock_id',
    ];
    foreach ($dedicated_table_definitions as $field_name => $definition) {
\Drupal::messenger()->addMessage("DEBUG ChadoTableMapping::create field_name $field_name"); //+debug
      $tables = [
        $table_mapping
          ->getDedicatedDataTableName($definition),
      ];
      if ($revisionable && $definition
        ->isRevisionable()) {
        $tables[] = $table_mapping
          ->getDedicatedRevisionTableName($definition);
      }
      foreach ($tables as $table_name) {
        $table_mapping
          ->setFieldNames($table_name, [
          $field_name,
        ]);
        $table_mapping
          ->setExtraColumns($table_name, $extra_columns);
      }
    }
// \Drupal::messenger()->addMessage("DEBUG ChadoTableMapping::create result: " . print_r($table_mapping, TRUE)); //+debug
    return $table_mapping;
  }

  /**
   * {@inheritdoc}
   */
  public function getFieldColumnName(FieldStorageDefinitionInterface $storage_definition, $property_name) {
\Drupal::messenger()->addMessage('DEBUG ChadoTableMapping::getFieldColumnName property_name:' . $property_name, \Drupal\Core\Messenger\MessengerInterface::TYPE_STATUS, TRUE); //+debug
// \Drupal::messenger()->addMessage('DEBUG ChadoTableMapping::getFieldColumnName field_name:' . $field_name, \Drupal\Core\Messenger\MessengerInterface::TYPE_STATUS, TRUE); //+debug
// \Drupal::messenger()->addMessage('DEBUG ChadoTableMapping::getFieldColumnName FSD:' . print_r($storage_definition, TRUE), \Drupal\Core\Messenger\MessengerInterface::TYPE_STATUS, TRUE); //+debug

    // Check if it is a Chado field.
    if (!is_a($storage_definition, \Drupal\field\Entity\FieldStorageConfig::class)
        || ($storage_definition->get('module') != 'stockprop_field')
    ) {
      \Drupal::messenger()->addMessage('DEBUG ChadoTableMapping::getFieldColumnName parent', \Drupal\Core\Messenger\MessengerInterface::TYPE_STATUS, TRUE); //+debug
      return parent::getFieldColumnName($storage_definition, $property_name);
    }
//  \Drupal::messenger()->addMessage('DEBUG ChadoTableMapping::getFieldColumnName module:' . $storage_definition->get('module'), \Drupal\Core\Messenger\MessengerInterface::TYPE_STATUS, TRUE); //+debug

    $field_name = $storage_definition->getName();
if ('entity_id' == $property_name) {
  return 'stock_id';
}

    if ($this->allowsSharedTableStorage($storage_definition)) {
\Drupal::messenger()->addMessage('DEBUG 0', \Drupal\Core\Messenger\MessengerInterface::TYPE_STATUS, TRUE); //+debug
      $column_name = count($storage_definition->getColumns()) == 1 ? $field_name : $field_name . '__' . $property_name;
    }
    elseif ($this
      ->requiresDedicatedTableStorage($storage_definition)
    ) {
\Drupal::messenger()->addMessage('DEBUG 1', \Drupal\Core\Messenger\MessengerInterface::TYPE_STATUS, TRUE); //+debug
      if ($property_name == TableMappingInterface::DELTA) {
        $column_name = 'delta';
      }
      else {
        $column_name = !in_array($property_name, $this
          ->getReservedColumns()) ? $field_name . '_' . $property_name : $property_name;
      }
    }
    else {
      throw new SqlContentEntityStorageException("Column information not available for the '{$field_name}' field.");
    }
\Drupal::messenger()->addMessage('DEBUG ChadoTableMapping::getFieldColumnName column_name:' . $column_name, \Drupal\Core\Messenger\MessengerInterface::TYPE_STATUS, TRUE); //+debug
    return $column_name;
  }
}