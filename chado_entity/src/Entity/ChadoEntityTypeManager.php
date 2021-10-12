<?php

namespace Drupal\chado_entity\Entity;

use Drupal\Component\Plugin\Exception\InvalidPluginDefinitionException;
use Drupal\Core\Entity\EntityTypeManager as CoreEntityTypeManager;

class ChadoEntityTypeManager extends CoreEntityTypeManager {

  /**
   * {@inheritdoc}
   */
  public function getHandler($entity_type_id, $handler_type) {
    if (!isset($this->handlers[$handler_type][$entity_type_id])) {
      $definition = $this->getDefinition($entity_type_id);

      // Here we add the custom storage handler to all content entities.
      if (get_class($definition) === 'Drupal\Core\Entity\ContentEntityType') {
        $definition->setHandlerClass('storage', 'Drupal\chado_entity\Storage\ChadoEntityStorage');
      }

      $class = $definition->getHandlerClass($handler_type);
      if (!$class) {
        throw new InvalidPluginDefinitionException($entity_type_id, sprintf('The "%s" entity type did not specify a %s handler.', $entity_type_id, $handler_type));
      }
      $this->handlers[$handler_type][$entity_type_id] = $this->createHandlerInstance($class, $definition);
    }

    return $this->handlers[$handler_type][$entity_type_id];
  }

}