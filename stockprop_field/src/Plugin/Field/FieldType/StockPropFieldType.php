<?php

namespace Drupal\stockprop_field\Plugin\Field\FieldType;

use Drupal\Component\Utility\Random;
use Drupal\Core\Field\FieldDefinitionInterface;
use Drupal\Core\Field\FieldItemBase;
use Drupal\Core\Field\FieldStorageDefinitionInterface;
use Drupal\Core\Form\FormStateInterface;
use Drupal\Core\StringTranslation\TranslatableMarkup;
use Drupal\Core\TypedData\DataDefinition;

/**
 * Plugin implementation of the 'stockprop_field_type' field type.
 *
 * @see https://api.drupal.org/api/drupal/core%21lib%21Drupal%21Core%21Field%21Annotation%21FieldType.php/class/FieldType/9.3.x
 *
 * @todo: persist_with_no_fields does not seem to work.
 *
 * @FieldType(
 *   id = "stockprop_field_type",
 *   label = @Translation("Stock prop field type"),
 *   description = @Translation("Stock Property field type."),
 *   default_widget = "stockprop_widget_type",
 *   default_formatter = "stockprop_formatter_type",
 *   persist_with_no_fields = TRUE,
 *   category = "Chado"
 * )
 */
class StockPropFieldType extends FieldItemBase {

  /**
   * {@inheritdoc}
   */
  public static function calculateDependencies(FieldDefinitionInterface $field_definition) {
    return [
      'module' => [
        'tripal_biodb',
        'chado_entity',
      ],
    ];
  }

  /**
   * {@inheritdoc}
   */
  public static function calculateStorageDependencies(FieldStorageDefinitionInterface $field_definition) {
    return [
      'module' => [
        'tripal_biodb',
        'chado_entity',
      ],
    ];
  }

  /**
   * {@inheritdoc}
   */
  public static function propertyDefinitions(FieldStorageDefinitionInterface $field_definition) {
    // Prevent early t() calls by using the TranslatableMarkup.
    $properties['type_id'] = DataDefinition::create('integer')
      ->setLabel(new TranslatableMarkup('Type ID'))
      ->setRequired(TRUE);
    $properties['value'] = DataDefinition::create('string')
      ->setLabel(new TranslatableMarkup('Text value'))
      ->setSetting('case_sensitive', $field_definition->getSetting('case_sensitive'))
      // Can be empty to remove a value.
      ->setRequired(TRUE);
    $properties['rank'] = DataDefinition::create('integer')
      ->setLabel(new TranslatableMarkup('Rank'))
      ->setRequired(FALSE);
    return $properties;
  }

  /**
   * {@inheritdoc}
   *
   * @see https://www.drupal.org/docs/7/api/schema-api/data-types/data-types-overview
   * @see https://api.drupal.org/api/drupal/core!lib!Drupal!Core!Database!database.api.php/group/schemaapi/9.3.x
   */
  public static function schema(FieldStorageDefinitionInterface $field_definition) {
    $schema = [
      'columns' => [
        'stockprop_id' => [
          'type' => 'serial',
          'size' => 'big',
          'pgsql_type' => 'bigserial',
          'not null' => TRUE,
        ],
        'type_id' => [
          'type' => 'int',
          'size' => 'big',
          'pgsql_type' => 'bigint',
          'not null' => TRUE,
        ],
        'value' => [
          'type' => 'text',
        ],
        'rank' => [
          'type' => 'int',
          'size' => 'medium',
          'pgsql_type' => 'int',
          'not null' => TRUE,
          'default' => 0,
        ],
      ],
      // We don't have stock_id at that time. May be a problem to check.
      // 'unique keys' => [
      //   'stockprop_c1' => [
      //     'stock_id',
      //     'type_id',
      //     'rank',
      //   ],
      // ],
      'foreign keys' => [
        // 'stockprop_stock_id_fkey' => [
        //   'table' => 'stock',
        //   'columns' => [
        //     'stock_id' => 'stock_id',
        //   ],
        // ],
        'stockprop_type_id_fkey' => [
          'table' => 'cvterm',
          'columns' => [
            'type_id' => 'cvterm_id',
          ],
        ],
      ],
    ];

    return $schema;
  }

  /**
   * {@inheritdoc}
   */
  public function getConstraints() {
    $constraints = parent::getConstraints();

    $constraint_manager = \Drupal::typedDataManager()->getValidationConstraintManager();
    $constraints[] = $constraint_manager->create('ComplexData', [
      'type_id' => [
        'Range' => [
          'min' => 0,
          'minMessage' => t('%name: The type_id integer must be larger or equal to %min.', [
            '%name' => $this->getFieldDefinition()->getLabel(),
            '%min' => 0,
          ]),
        ],
      ],
      'rank' => [
        'Range' => [
          'min' => 0,
          'minMessage' => t('%name: The rank integer must be larger or equal to %min.', [
            '%name' => $this->getFieldDefinition()->getLabel(),
            '%min' => 0,
          ]),
        ],
      ],
    ]);

    return $constraints;
  }

  /**
   * {@inheritdoc}
   */
  public static function generateSampleValue(FieldDefinitionInterface $field_definition) {
    $random = new Random();
    $values['type_id'] = 1;
    $values['value'] = $random->word(mt_rand(1, $field_definition->getSetting('max_length')));
    $values['rank'] = 0;
    return $values;
  }

  /**
   * {@inheritdoc}
   */
  public function storageSettingsForm(array &$form, FormStateInterface $form_state, $has_data) {
    $elements = [];
    // Maybe configure auto-complete for CV terms or other foreign keys?
    return $elements;
  }

  /**
   * {@inheritdoc}
   */
  public static function mainPropertyName() {
    return 'value';
  }

  /**
   * {@inheritdoc}
   */
  public function isEmpty() {
    $value = $this->get('value')->getValue();
    return ($value === NULL) || ($value === '');
  }

  // public function getDataDefinition()
}
