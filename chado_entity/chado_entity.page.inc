<?php

/**
 * @file
 * Contains default_entity.page.inc.
 *
 * Page callback for Default entity entities.
 */

use Drupal\Core\Render\Element;

/**
 * Prepares variables for Default entity templates.
 *
 * Default template: default_entity.html.twig.
 *
 * @param array $variables
 *   An associative array containing:
 *   - elements: An associative array containing the user information and any
 *   - attributes: HTML attributes for the containing element.
 */
function template_preprocess_chado_entity(array &$variables) {
  // Fetch DefaultEntity Entity Object.
  $chado_entity = $variables['elements']['#chado_entity'];

  // Helpful $content variable for templates.
  foreach (Element::children($variables['elements']) as $key) {
    $variables['content'][$key] = $variables['elements'][$key];
  }
}