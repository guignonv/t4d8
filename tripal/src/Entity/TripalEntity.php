<?php

namespace Drupal\tripal\Entity;

use Drupal\Core\Entity\EntityStorageInterface;
use Drupal\Core\Field\BaseFieldDefinition;
use Drupal\Core\Entity\ContentEntityBase;
use Drupal\Core\Entity\EntityChangedTrait;
use Drupal\Core\Entity\EntityTypeInterface;
use Drupal\user\UserInterface;

/**
 * Defines the Tripal Content entity.
 *
 * @ingroup tripal
 *
 * @ContentEntityType(
 *   id = "tripal_entity",
 *   label = @Translation("Tripal Content"),
 *   bundle_label = @Translation("Tripal Content type"),
 *   handlers = {
 *     "storage" = "Drupal\tripal\Entity\TripalContentEntityStorage",
 *     "view_builder" = "Drupal\Core\Entity\EntityViewBuilder",
 *     "list_builder" = "Drupal\tripal\ListBuilders\TripalEntityListBuilder",
 *     "views_data" = "Drupal\tripal\Entity\TripalEntityViewsData",
 *
 *     "form" = {
 *       "default" = "Drupal\tripal\Form\TripalEntityForm",
 *       "add" = "Drupal\tripal\Form\TripalEntityForm",
 *       "edit" = "Drupal\tripal\Form\TripalEntityForm",
 *       "delete" = "Drupal\tripal\Form\TripalEntityDeleteForm",
 *     },
 *     "access" = "Drupal\tripal\Access\TripalEntityAccessControlHandler",
 *     "route_provider" = {
 *       "html" = "Drupal\tripal\TripalEntityHtmlRouteProvider",
 *     },
 *   },
 *   base_table = "tripal_entity",
 *   admin_permission = "access tripal content overview",
 *   entity_keys = {
 *     "id" = "id",
 *     "bundle" = "type",
 *     "uid" = "user_id",
 *     "status" = "status",
 *   },
 *   links = {
 *     "canonical" = "/bio_data/{tripal_entity}",
 *     "add-page" = "/bio_data/add",
 *     "add-form" = "/bio_data/add/{tripal_entity_type}",
 *     "edit-form" = "/bio_data/{tripal_entity}/edit",
 *     "delete-form" = "/bio_data/{tripal_entity}/delete",
 *     "collection" = "/admin/content/bio_data",
 *   },
 *   bundle_entity_type = "tripal_entity_type",
 *   field_ui_base_route = "entity.tripal_entity_type.edit_form"
 * )
 */
class TripalEntity extends ContentEntityBase implements TripalEntityInterface {

  use EntityChangedTrait;

  /**
   * Constructs a new Tripal entity object, without permanently saving it.
   *
   * @code
      $values = [
        'title' => 'laceytest'.time(),
        'type' => 'bio_data_1',
        'uid' => 1,
      ];
      $entity = \Drupal\tripal\Entity\TripalEntity::create($values);
      $entity->save();
   * @endcode
   *
   * @param array $values
   *   - *title: the title of the entity.
   *   - *uid: the user_id of the user who authored the content.
   *   - *type: the type of tripal entity this is (e.g. bio_data_1)
   *   - status: whether the entity is published or not (boolean)
   *   - created: the unix timestamp for when this content was created.
   * @return
   *  The newly created entity.
   */
  public static function create(array $values = []) {
    return parent::create($values);
  }

  /**
   * {@inheritdoc}
   */
  public static function preCreate(EntityStorageInterface $storage_controller, array &$values) {
    parent::preCreate($storage_controller, $values);
    $values += array(
      'user_id' => \Drupal::currentUser()->id(),
    );
  }

  /**
   * {@inheritdoc}
   */
  public function getID() {
    $entity_id = $this->id();
    if (is_array($entity_id) AND array_key_exists(0, $entity_id)) {
      return $entity_id[0]['value'];
    }
    return $entity_id;
  }

  /**
   * {@inheritdoc}
   */
  public function label() {
    $title = $this->title->getValue();
    if ($title) {
      return $title[0]['value'];
    }
    else {
      return '';
    }
  }

  /**
   * {@inheritdoc}
   */
  public function setTitle($title = NULL, $cache = []) {

    if (isset($cache['bundle'])) {
      $bundle = $cache['bundle'];
    }
    else {
      $bundle = \Drupal\tripal\Entity\TripalEntityType::load($this->getType());
    }

    // If no title was supplied then we should try to generate one using the
    // default format set by admins.
    // @todo figure out how to override the title while still allowing
    //   tokenized titles to be updated on edit.
    $title = $bundle->getTitleFormat();
    $title = $this->replaceTokens($title, ['tripal_entity_type' => $bundle]);

    $this->title = $title;
  }

  /**
   * {@inheritdoc}
   */
  public function getTitle() {
    return $this->title->getString();
  }

  /**
   * Sets the URL alias for the current entity.
   *
   * @param $alias
   *   The alias to use. It can contain tokens the correspond to field values.
   *   Token should be be compatible with those returned by
   *   tripal_get_entity_tokens().
   * @param $cache
   *   This array is used to store objects you want to cache for performance
   *   reasons, as well as, cache related options. The following are supported:
   *   - TripalEntityType $bundle
   *       The bundle for the current entity.
   */
  public function setAlias($path_alias = NULL, $cache = []) {

    $system_path = "/bio_data/" . $this->getID();
    $langcode = \Drupal::languageManager()->getCurrentLanguage()->getId();

    // If no alias was supplied then we should try to generate one using the
    // default format set by admins.
    if (!$path_alias) {

      // Load the TripalEntityType entity for this TripalEntity (if it's not
      // cached). First get the format for the url alias based on the bundle
      // of the entity. Then replace all the tokens with values from the entity fields.
      if (isset($cache['bundle'])) {
        $bundle = $cache['bundle'];
      }
      else {
        $bundle = \Drupal\tripal\Entity\TripalEntityType::load($this->getType());
      }

      $path_alias = $bundle->getURLFormat();
      $path_alias = $this->replaceTokens($path_alias,
        ['tripal_entity_type' => $bundle]);

    }

    // Ensure there is a leading slash.
    if ($path_alias[0] != '/') {
      $path_alias = '/' . $path_alias;
    }

    // Make sure the path alias is URL friendly.
    $path_alias = str_replace(['%2F', '+'], ['/', '-'], urlencode($path_alias));

    // Now finally, set the alias.
    $path = \Drupal::entityTypeManager()->getStorage('path_alias')->create([
      'path' => $system_path,
      'alias' => $path_alias,
      'langcode' => $langcode,
    ]);
    $path->save();
  }

  /**
   * {@inheritdoc}
   */
  public function getType() {
    return $this->bundle();
  }

  /**
   * {@inheritdoc}
   */
  public function getCreatedTime() {
    return $this->get('created')->value;
  }

  /**
   * {@inheritdoc}
   */
  public function setCreatedTime($timestamp) {
    $this->set('created', $timestamp);
    return $this;
  }

  /**
   * {@inheritdoc}
   */
  public function getOwner() {
    return $this->get('uid')->entity;
  }

  /**
   * {@inheritdoc}
   */
  public function getOwnerId() {
    return $this->get('uid')->target_id;
  }

  /**
   * {@inheritdoc}
   */
  public function setOwnerId($uid) {
    $this->set('uid', $uid);
    return $this;
  }

  /**
   * {@inheritdoc}
   */
  public function setOwner(UserInterface $account) {
    $this->set('uid', $account->id());
    return $this;
  }

  /**
   * {@inheritdoc}
   */
  public function isPublished() {
    return (bool) $this->getEntityKey('status');
  }

  /**
   * {@inheritdoc}
   */
  public function setPublished($published) {
    $this->set('status', $published ? NODE_PUBLISHED : NODE_NOT_PUBLISHED);
    return $this;
  }

  /**
   * {@inheritdoc}
   */
  public function replaceTokens($string, $cache = []) {

    // Pull any items out of the cache.
    if (isset($cache['bundle'])) {
      $bundle_entity = $cache['bundle'];
    }
    else {
      $bundle_entity = \Drupal\tripal\Entity\TripalEntityType::load($this->getType());
    }

    // Determine which tokens were used in the format string
    $used_tokens = [];
    if (preg_match_all('/\[.*?\]/', $string, $matches)) {
      $used_tokens = $matches[0];
    }

    // If there are no tokens then just return the string.
    if (count($used_tokens) == 0) {
      return $string;
    }

    // @todo UPGRADE THIS CODE ONCE TRIPAL FIELDS HAVE BEEN ADDED
    //
    // If the fields are not loaded for the entity then we want to load them
    // but we won't do a field_attach_load() as that will load all of the
    // fields. For syncing (publishing) of content loading all fields for
    // all synced entities causes extreme slowness, so we'll only attach
    // the necessary fields for replacing tokens.
    // $attach_fields = [];
    //
    // foreach ($used_tokens as $token) {
    //   $token = preg_replace('/[\[\]]/', '', $token);
    //   $elements = explode(',', $token);
    //   $field_name = array_shift($elements);
    //
    //   if (!property_exists($entity, $field_name) or empty($entity->{$field_name})) {
    //     $field = field_info_field($field_name);
    //     $storage = $field['storage'];
    //     $attach_fields[$storage['type']]['storage'] = $storage;
    //     $attach_fields[$storage['type']]['fields'][] = $field;
    //   }
    // }
    //
    // // If we have any fields that need attaching, then do so now.
    // if (count(array_keys($attach_fields)) > 0) {
    //   foreach ($attach_fields as $storage_type => $details) {
    //     $field_ids = [];
    //     $storage = $details['storage'];
    //     $fields = $details['fields'];
    //     foreach ($fields as $field) {
    //       $field_ids[$field['id']] = [$entity->id];
    //     }
    //     $entities = [$entity->id => $entity];
    //     module_invoke($storage['module'], 'field_storage_load', 'TripalEntity',
    //       $entities, FIELD_LOAD_CURRENT, $field_ids, []);
    //   }
    // }

    // Now that all necessary fields are attached process the tokens.
    foreach ($used_tokens as $token) {
      $token = preg_replace('/[\[\]]/', '', $token);
      $elements = explode(',', $token);
      $field_name = array_shift($elements);
      $value = '';

      // The TripalBundle__bundle_id is a special token for substituting the
      // bundle id.
      if ($token === 'TripalBundle__bundle_id') {
        // This token should be the id of the TripalBundle.
        $value = $bundle_entity->getID();
      }
      // The TripalBundle__bundle_id is a special token for substituting the
      // entity id.
      elseif ($token === 'TripalEntity__entity_id') {
        // This token should be the id of the TripalEntity.
        $value = $this->getID();
      }
      elseif ($token == 'TripalEntityType__label') {
        $value = $bundle_entity->getLabel();
      }
      else {
        $value_obj = $this->get($field_name);
        if ($value_obj) {
          $value_raw = $value_obj->getValue();
          $value = $value_raw[0]['value'];

          // If the value is an array it means we have sub elements and we can
          // descend through the array to look for matching value.
          if (is_array($value) and count($elements) > 0) {
            // @todo we still need to handle this case.
            $value = '';
            //$value = _tripal_replace_entity_tokens_for_elements($elements, $value);
          }
        }
      }

      // We can't support tokens that have multiple elements (i.e. in an array).
      if (is_array($value)) {
        $string = str_replace('[' . $token . ']', '', $string);
      }
      else {
        $string = str_replace('[' . $token . ']', $value, $string);
      }
    }

    return $string;
  }

  /**
   * {@inheritdoc}
   */
  public static function baseFieldDefinitions(EntityTypeInterface $entity_type) {
    $fields = parent::baseFieldDefinitions($entity_type);

    $fields['uid'] = BaseFieldDefinition::create('entity_reference')
      ->setLabel(t('Authored by'))
      ->setDescription(t('The user ID of author of the Tripal Content entity.'))
      ->setRevisionable(TRUE)
      ->setSetting('target_type', 'user')
      ->setSetting('handler', 'default')
      ->setTranslatable(TRUE)
      ->setDisplayOptions('view', array(
        'label' => 'hidden',
        'type' => 'author',
        'weight' => 0,
      ))
      ->setDisplayOptions('form', array(
        'type' => 'entity_reference_autocomplete',
        'weight' => 5,
        'settings' => array(
          'match_operator' => 'CONTAINS',
          'size' => '60',
          'autocomplete_type' => 'tags',
          'placeholder' => '',
        ),
      ))
      ->setDisplayConfigurable('form', TRUE)
      ->setDisplayConfigurable('view', TRUE);

    $fields['title'] = BaseFieldDefinition::create('string')
      ->setLabel(t('Title'))
      ->setDescription(t('The title of this entity.'))
      ->setSettings(array(
        'max_length' => 1024,
        'text_processing' => 0,
      ))
      ->setDefaultValue('')
      ->setDisplayOptions('view', array(
        'label' => 'above',
        'type' => 'string',
        'weight' => -4,
      ))
      ->setDisplayOptions('form', array(
        'type' => 'string_textfield',
        'weight' => -4,
      ))
      ->setDisplayConfigurable('form', TRUE)
      ->setDisplayConfigurable('view', TRUE);

    $fields['status'] = BaseFieldDefinition::create('boolean')
      ->setLabel(t('Publishing status'))
      ->setDescription(t('A boolean indicating whether the Tripal Content is published.'))
      ->setDefaultValue(TRUE);

    $fields['created'] = BaseFieldDefinition::create('created')
      ->setLabel(t('Created'))
      ->setDescription(t('The time that the entity was created.'));

    $fields['changed'] = BaseFieldDefinition::create('changed')
      ->setLabel(t('Changed'))
      ->setDescription(t('The time that the entity was last edited.'));

    return $fields;
  }

}
