<?php

namespace Drupal\stockprop_field\Plugin\Field\FieldWidget;

use Drupal\Core\Field\FieldItemListInterface;
use Drupal\Core\Field\WidgetBase;
use Drupal\Core\Form\FormStateInterface;

/**
 * Plugin implementation of the 'stockprop_widget_type' widget.
 *
 * @FieldWidget(
 *   id = "stockprop_widget_type",
 *   module = "stockprop_field",
 *   label = @Translation("Stock prop widget type"),
 *   field_types = {
 *     "stockprop_field_type"
 *   }
 * )
 */
class StockPropWidgetType extends WidgetBase {

  /**
   * {@inheritdoc}
   */
  public static function defaultSettings() {
    return [
      'placeholder' => '',
    ] + parent::defaultSettings();
  }

  /**
   * {@inheritdoc}
   */
  public function settingsForm(array $form, FormStateInterface $form_state) {
    $elements = [];

    $elements['placeholder'] = [
      '#type' => 'textfield',
      '#title' => t('Placeholder'),
      '#default_value' => $this->getSetting('placeholder'),
      '#description' => t('Text that will be shown inside the field until a value is entered. This hint is usually a sample value or a brief description of the expected format.'),
    ];

    return $elements;
  }

  /**
   * {@inheritdoc}
   */
  public function settingsSummary() {
    $summary = [];

    if (!empty($this->getSetting('placeholder'))) {
      $summary[] = t('Placeholder: @placeholder', ['@placeholder' => $this->getSetting('placeholder')]);
    }

    return $summary;
  }

  /**
   * {@inheritdoc}
   */
  public function formElement(FieldItemListInterface $items, $delta, array $element, array &$form, FormStateInterface $form_state) {
    $element += [
      '#type' => 'fieldset',
      '#title' => $this->t('Stock property'),
    ];

    $element['type_id'] = [
      '#title' => $this->t('Type ID'),
      '#title_display' => 'before',
      '#type' => 'number',
      '#min' => 0,
      '#default_value' => isset($items[$delta]->type_id) ? $items[$delta]->type_id : 1,
      '#required' => TRUE,
      '#description' => $this->t('CV term identifier (cvterm_id) defining the type of this property.'),
    ];

    $element['value'] = [
      '#title' => $this->t('Value'),
      '#title_display' => 'before',
      '#type' => 'textfield',
      '#default_value' => isset($items[$delta]->value) ? $items[$delta]->value : NULL,
      '#placeholder' => $this->getSetting('placeholder'),
      '#required' => FALSE,
      '#description' => $this->t('Value of the property.'),
    ];

    $element['rank'] = [
      '#title' => $this->t('Rank'),
      '#title_display' => 'before',
      '#type' => 'number',
      '#min' => 0,
      '#default_value' => isset($items[$delta]->rank) ? $items[$delta]->rank : 0,
      '#required' => FALSE,
      '#description' => $this->t('Rank used to sort properties.'),
    ];

    return $element;
  }

}
