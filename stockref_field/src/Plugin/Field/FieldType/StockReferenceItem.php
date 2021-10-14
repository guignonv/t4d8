<?php

namespace Drupal\stockref_field\Plugin\Field\FieldType;

use Drupal\Component\Utility\Random;
use Drupal\Core\TypedData\DataDefinition;

use Drupal\Component\Utility\Html;
use Drupal\Component\Utility\NestedArray;
use Drupal\Core\Entity\ContentEntityStorageInterface;
use Drupal\Core\Entity\EntityInterface;
use Drupal\Core\Entity\EntityTypeInterface;
use Drupal\Core\Entity\FieldableEntityInterface;
use Drupal\Core\Entity\TypedData\EntityDataDefinition;
use Drupal\Core\Field\FieldDefinitionInterface;
use Drupal\Core\Field\FieldException;
use Drupal\Core\Field\FieldItemBase;
use Drupal\Core\Field\FieldStorageDefinitionInterface;
use Drupal\Core\Field\PreconfiguredFieldUiOptionsInterface;
use Drupal\Core\Form\FormStateInterface;
use Drupal\Core\Form\OptGroup;
use Drupal\Core\Render\Element;
use Drupal\Core\Session\AccountInterface;
use Drupal\Core\StringTranslation\TranslatableMarkup;
use Drupal\Core\TypedData\DataReferenceDefinition;
use Drupal\Core\TypedData\DataReferenceTargetDefinition;
use Drupal\Core\TypedData\OptionsProviderInterface;
use Drupal\Core\Validation\Plugin\Validation\Constraint\AllowedValuesConstraint;

/**
 * Plugin implementation of the 'stockref_field_type' field type.
 *
 * @see https://api.drupal.org/api/drupal/core%21lib%21Drupal%21Core%21Field%21Annotation%21FieldType.php/class/FieldType/9.3.x
 *
 * @todo: persist_with_no_fields does not seem to work.
 *
 * @FieldType(
 *   id = "stock_reference",
 *   label = @Translation("Stock reference"),
 *   description = @Translation("Reference to an existing stock."),
 *   default_widget = "stock_reference_autocomplete",
 *   default_formatter = "stock_reference_label",
 *   list_class = "\Drupal\stockref_field\Plugin\Field\StockReferenceFieldItemList",
 *   category = "Chado"
 * )
 */
class StockReferenceItem extends FieldItemBase implements OptionsProviderInterface, PreconfiguredFieldUiOptionsInterface {

  /**
   * {@inheritdoc}
   */
  public static function defaultStorageSettings() {
    return [
//      'target_type' => \Drupal::moduleHandler()->moduleExists('node') ? 'node' : 'user',
      'target_type' => 'chado_entity',
    ] + parent::defaultStorageSettings();
  }

  /**
   * {@inheritdoc}
   */
  public static function defaultFieldSettings() {
    return [
      'handler' => 'default',
      'handler_settings' => [],
    ] + parent::defaultFieldSettings();
  }

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
  public static function onDependencyRemoval(FieldDefinitionInterface $field_definition, array $dependencies) {
    $changed = parent::onDependencyRemoval($field_definition, $dependencies);
    $entity_type_manager = \Drupal::entityTypeManager();
    $target_entity_type = $entity_type_manager->getDefinition($field_definition->getFieldStorageDefinition()->getSetting('target_type'));

    // Try to update the default value config dependency, if possible.
    if ($default_value = $field_definition->getDefaultValueLiteral()) {
      $entity_repository = \Drupal::service('entity.repository');
      foreach ($default_value as $key => $value) {
        if (is_array($value) && isset($value['target_uuid'])) {
          $entity = $entity_repository->loadEntityByUuid($target_entity_type->id(), $value['target_uuid']);
          // @see \Drupal\Core\Field\EntityReferenceFieldItemList::processDefaultValue()
          if ($entity && isset($dependencies[$entity->getConfigDependencyKey()][$entity->getConfigDependencyName()])) {
            unset($default_value[$key]);
            $changed = TRUE;
          }
        }
      }
      if ($changed) {
        $field_definition->setDefaultValue($default_value);
      }
    }

    return $changed;
  }

  /**
   * {@inheritdoc}
   */
  public function getPossibleValues(AccountInterface $account = NULL) {
    return $this->getSettableValues($account);
  }

  /**
   * {@inheritdoc}
   */
  public function getPossibleOptions(AccountInterface $account = NULL) {
    return $this->getSettableOptions($account);
  }

  /**
   * {@inheritdoc}
   */
  public function getSettableValues(AccountInterface $account = NULL) {
    // Flatten options first, because "settable options" may contain group
    // arrays.
    $flatten_options = OptGroup::flattenOptions($this->getSettableOptions($account));
    return array_keys($flatten_options);
  }

  /**
   * {@inheritdoc}
   */
  public function getSettableOptions(AccountInterface $account = NULL) {
    $field_definition = $this->getFieldDefinition();
    if (!$options = \Drupal::service('plugin.manager.entity_reference_selection')->getSelectionHandler($field_definition, $this->getEntity())->getReferenceableEntities()) {
      return [];
    }

    // Rebuild the array by changing the bundle key into the bundle label.
    $target_type = $field_definition->getSetting('target_type');
    $bundles = \Drupal::service('entity_type.bundle.info')->getBundleInfo($target_type);

    $return = [];
    foreach ($options as $bundle => $entity_ids) {
      // The label does not need sanitizing since it is used as an optgroup
      // which is only supported by select elements and auto-escaped.
      $bundle_label = (string) $bundles[$bundle]['label'];
      $return[$bundle_label] = $entity_ids;
    }

    return count($return) == 1 ? reset($return) : $return;
  }

  /**
   * Render API callback: Processes the field settings form.
   *
   * Allows access to the form state.
   *
   * @see static::fieldSettingsForm()
   */
  public static function fieldSettingsAjaxProcess($form, FormStateInterface $form_state) {
    static::fieldSettingsAjaxProcessElement($form, $form);
    return $form;
  }

  /**
   * Adds entity_reference specific properties to AJAX form elements from the
   * field settings form.
   *
   * @see static::fieldSettingsAjaxProcess()
   */
  public static function fieldSettingsAjaxProcessElement(&$element, $main_form) {
    if (!empty($element['#ajax'])) {
      $element['#ajax'] = [
        'callback' => [static::class, 'settingsAjax'],
        'wrapper' => $main_form['#id'],
        'element' => $main_form['#array_parents'],
      ];
    }

    foreach (Element::children($element) as $key) {
      static::fieldSettingsAjaxProcessElement($element[$key], $main_form);
    }
  }

  /**
   * Render API callback: Moves entity_reference specific Form API elements
   * (i.e. 'handler_settings') up a level for easier processing by the
   * validation and submission handlers.
   *
   * @see _entity_reference_field_settings_process()
   */
  public static function formProcessMergeParent($element) {
    $parents = $element['#parents'];
    array_pop($parents);
    $element['#parents'] = $parents;
    return $element;
  }

  /**
   * Ajax callback for the handler settings form.
   *
   * @see static::fieldSettingsForm()
   */
  public static function settingsAjax($form, FormStateInterface $form_state) {
    return NestedArray::getValue($form, $form_state->getTriggeringElement()['#ajax']['element']);
  }

  /**
   * Submit handler for the non-JS case.
   *
   * @see static::fieldSettingsForm()
   */
  public static function settingsAjaxSubmit($form, FormStateInterface $form_state) {
    $form_state->setRebuild();
  }

  /**
   * {@inheritdoc}
   */
  public static function propertyDefinitions(FieldStorageDefinitionInterface $field_definition) {
    $settings = $field_definition->getSettings();
    $target_type_info = \Drupal::entityTypeManager()->getDefinition($settings['target_type']);

    $object_id_definition = DataReferenceTargetDefinition::create('integer')
      ->setLabel(new TranslatableMarkup('@label ID', ['@label' => $target_type_info->getLabel()]))
      ->setSetting('unsigned', TRUE);

    $object_id_definition->setRequired(TRUE);
    $properties['object_id'] = $object_id_definition;

    $properties['entity'] = DataReferenceDefinition::create('entity')
      ->setLabel($target_type_info->getLabel())
      ->setDescription(new TranslatableMarkup('The referenced stock'))
      // The entity object is computed out of the entity ID.
      ->setComputed(TRUE)
      ->setReadOnly(FALSE)
      ->setTargetDefinition(EntityDataDefinition::create($settings['target_type']))
      // We can add a constraint for the target entity type. The list of
      // referenceable bundles is a field setting, so the corresponding
      // constraint is added dynamically in ::getConstraints().
      ->addConstraint('EntityType', $settings['target_type']);

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
        'stock_relationship_id' => [
          'type' => 'serial',
          'size' => 'big',
          'pgsql_type' => 'bigserial',
          'not null' => TRUE,
        ],
        'subject_id' => [
          'type' => 'int',
          'size' => 'big',
          'pgsql_type' => 'bigint',
          'not null' => TRUE,
        ],
        'object_id' => [
          'type' => 'int',
          'size' => 'big',
          'pgsql_type' => 'bigint',
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
      'unique keys' => [
        'rstock_relationship_c1' => [
          'subject_id',
          'object_id',
          'type_id',
          'rank',
        ],
      ],
      'foreign keys' => [
         'rstock_relationship_object_id_fkey' => [
           'table' => 'stock',
           'columns' => [
             'object_id' => 'stock_id',
           ],
         ],
         'rstock_relationship_subject_id_fkey' => [
           'table' => 'stock',
           'columns' => [
             'subject_id' => 'stock_id',
           ],
         ],
        'rstock_relationship_type_id_fkey' => [
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

    // Remove the 'AllowedValuesConstraint' validation constraint because entity
    // reference fields already use the 'ValidReference' constraint.
    foreach ($constraints as $key => $constraint) {
      if ($constraint instanceof AllowedValuesConstraint) {
        unset($constraints[$key]);
      }
    }

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
  public function setValue($values, $notify = TRUE) {
    if (isset($values) && !is_array($values)) {
      // If either a scalar or an object was passed as the value for the item,
      // assign it to the 'entity' property since that works for both cases.
      $this->set('entity', $values, $notify);
    }
    else {
      parent::setValue($values, FALSE);
// \Drupal::messenger()->addMessage('DEBUG values ' . print_r($values, TRUE)); //+debug
if (is_array($values) && array_key_exists('target_id', $values)) {
  $values['object_id'] = $values['target_id'];
  unset($values['target_id']);
}
      // Support setting the field item with only one property, but make sure
      // values stay in sync if only property is passed.
      // NULL is a valid value, so we use array_key_exists().
      if (is_array($values) && array_key_exists('object_id', $values) && !isset($values['entity'])) {
        $this->onChange('object_id', FALSE);
      }
      elseif (is_array($values) && !array_key_exists('object_id', $values) && isset($values['entity'])) {
        $this->onChange('entity', FALSE);
      }
      elseif (is_array($values) && array_key_exists('object_id', $values) && isset($values['entity'])) {
        // If both properties are passed, verify the passed values match. The
        // only exception we allow is when we have a new entity: in this case
        // its actual id and object_id will be different, due to the new entity
        // marker.
        $entity_id = $this->get('entity')->getTargetIdentifier();
        // If the entity has been saved and we're trying to set both the
        // object_id and the entity values with a non-null target ID, then the
        // value for object_id should match the ID of the entity value. The
        // entity ID as returned by $entity->id() might be a string, but the
        // provided object_id might be an integer - therefore we have to do a
        // non-strict comparison.
        if (!$this->entity->isNew()
            && $values['object_id'] !== NULL
            && ($entity_id != $values['object_id'])
        ) {
          throw new \InvalidArgumentException('The target id and entity passed to the entity reference item do not match.');
        }
      }
      // Notify the parent if necessary.
      if ($notify && $this->parent) {
        $this->parent->onChange($this->getName());
      }
    }
  }

  /**
   * {@inheritdoc}
   */
  public function getValue() {
    $values = parent::getValue();

    // If there is an unsaved entity, return it as part of the field item values
    // to ensure idempotency of getValue() / setValue().
    if ($this->hasNewEntity()) {
      $values['entity'] = $this->entity;
    }
    return $values;
  }

  /**
   * {@inheritdoc}
   */
  public function onChange($property_name, $notify = TRUE) {
    // Make sure that the target ID and the target property stay in sync.
    if ($property_name == 'entity') {
      $property = $this->get('entity');
      $object_id = $property->isTargetNew() ? NULL : $property->getTargetIdentifier();
      $this->writePropertyValue('object_id', $object_id);
    }
    elseif ($property_name == 'object_id') {
      $this->writePropertyValue('entity', $this->target_id);
    }
    parent::onChange($property_name, $notify);
  }

  /**
   * {@inheritdoc}
   */
  public static function generateSampleValue(FieldDefinitionInterface $field_definition) {
    $random = new Random();
    $values['type_id'] = 1;
    $values['value'] = $random->word(mt_rand(1, $field_definition->getSetting('max_length')));
    $values['rank'] = 0;

    // An associative array keyed by the reference type, target type, and
    // bundle.
    static $recursion_tracker = [];

    $manager = \Drupal::service('plugin.manager.entity_reference_selection');

    // Instead of calling $manager->getSelectionHandler($field_definition)
    // replicate the behavior to be able to override the sorting settings.
    $options = [
      'target_type' => $field_definition->getFieldStorageDefinition()->getSetting('target_type'),
      'handler' => $field_definition->getSetting('handler'),
      'entity' => NULL,
    ] + $field_definition->getSetting('handler_settings') ?: [];

    $entity_type = \Drupal::entityTypeManager()->getDefinition($options['target_type']);
    $options['sort'] = [
      'field' => $entity_type->getKey('id'),
      'direction' => 'DESC',
    ];
    $selection_handler = $manager->getInstance($options);

    // Select a random number of references between the last 10 referenceable
    // entities created.
    if ($referenceable = $selection_handler->getReferenceableEntities(NULL, 'CONTAINS', 10)) {
      $group = array_rand($referenceable);
      $values['object_id'] = array_rand($referenceable[$group]);
    }

    return $values;
  }

  /**
   * {@inheritdoc}
   */
  public function storageSettingsForm(array &$form, FormStateInterface $form_state, $has_data) {
    $elements = [];
    // We may want to filter stock on type_id.
    // $element['target_type'] = [
    //   '#type' => 'select',
    //   '#title' => t('Type of item to reference'),
    //   '#default_value' => $this->getSetting('target_type'),
    //   '#required' => TRUE,
    //   '#disabled' => $has_data,
    //   '#size' => 1,
    // ];

    // Only allow the field to target entity types that have an ID key. This
    // is enforced in ::propertyDefinitions().
    $entity_type_manager = \Drupal::entityTypeManager();
    $filter = function (string $entity_type_id) use ($entity_type_manager): bool {
      return $entity_type_manager->getDefinition($entity_type_id)
        ->hasKey('id');
    };
    $options = \Drupal::service('entity_type.repository')->getEntityTypeLabels(TRUE);
    foreach ($options as $group_name => $group) {
      $element['target_type']['#options'][$group_name] = array_filter($group, $filter, ARRAY_FILTER_USE_KEY);
    }
    return $element;
  }

  /**
   * Determines whether the item holds an unsaved entity.
   *
   * This is notably used for "autocreate" widgets, and more generally to
   * support referencing freshly created entities (they will get saved
   * automatically as the hosting entity gets saved).
   *
   * @return bool
   *   TRUE if the item holds an unsaved entity.
   */
  public function hasNewEntity() {
    return !$this->isEmpty() && $this->object_id === NULL && $this->entity->isNew();
  }

  /**
   * {@inheritdoc}
   */
  public static function mainPropertyName() {
    return 'object_id';
  }

  /**
   * {@inheritdoc}
   */
  public function isEmpty() {
    // Avoid loading the entity by first checking the 'object_id'.
    if ($this->object_id !== NULL) {
      return FALSE;
    }
    if ($this->entity && $this->entity instanceof EntityInterface) {
      return FALSE;
    }
    return TRUE;
  }

  /**
   * {@inheritdoc}
   */
  public function preSave() {
    if ($this->hasNewEntity()) {
      // Save the entity if it has not already been saved by some other code.
      if ($this->entity->isNew()) {
        $this->entity->save();
      }
      // Make sure the parent knows we are updating this property so it can
      // react properly.
      $this->object_id = $this->entity->id();
    }
    if (!$this->isEmpty() && $this->object_id === NULL) {
      $this->object_id = $this->entity->id();
    }
  }

  /**
   * {@inheritdoc}
   */
  public static function getPreconfiguredOptions() {
    $options = [];

    // // Add all the commonly referenced entity types as distinct pre-configured
    // // options.
    // $entity_types = \Drupal::entityTypeManager()->getDefinitions();
    // $common_references = array_filter($entity_types, function (EntityTypeInterface $entity_type) {
    //   return $entity_type->isCommonReferenceTarget();
    // });
    // 
    // /** @var \Drupal\Core\Entity\EntityTypeInterface $entity_type */
    // foreach ($common_references as $entity_type) {
    //   $options[$entity_type->id()] = [
    //     'label' => $entity_type->getLabel(),
    //     'field_storage_config' => [
    //       'settings' => [
    //         'target_type' => $entity_type->id(),
    //       ],
    //     ],
    //   ];
    // }

    return $options;
  }
}
