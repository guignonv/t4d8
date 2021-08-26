<?php

namespace Drupal\Tests\tripal_chado\Functional\Services\Subclasses;

use Drupal\tripal_chado\Services\ChadoManager;

/**
 * Provides unprotected method for tests.
 */
class UnprotectedChadoManager extends ChadoManager {

  /**
   * Maximum number of tries to lock somehting.
   */
  public const MAX_ATTEMPTS = 5;

  /**
   * Time to wait between attempts in seconds.
   */
  public const ATTEMPTS_WAIT_TIME = 5;

  /**
   * An array of lock names used to avoid concurrent operations.
   *
   * Keys are lock names and values are array of locked schema names.
   *
   * Locks are generated during performAction() by lockAction() and released
   * once the action is done by releaseActionLock(). Unreleased locks will be
   * freed by this parent class destructor (unless overriden).
   *
   * @var array
   */
//  public $locks = [];

  /**
   * Logger used to log and report messages.
   *
   * @var object
   */
//  public $logger;

  /**
   * Database name (used for lock names).
   *
   * @var string
   */
//  public $db_name;

  /**
   * {@inheritdoc}
   */
  public static function sanitizeDbName(string $db_name = '') {
    return parent::sanitizeDbName($db_name);
  }

  /**
   * {@inheritdoc}
   */
  public static function getLockFilePath(
    string $lock_name,
    string $db_name = ''
  ) {
    return parent::getLockFilePath($lock_name, $db_name);
  }

  /**
   * {@inheritdoc}
   */
  public static function lockLockForModifications(string $lock_file) {
    return parent::lockLockForModifications($lock_file);
  }

  /**
   * {@inheritdoc}
   */
  public static function isLockFileExpired(string $lock_filepath) {
    return parent::isLockFileExpired($lock_filepath);
  }

  /**
   * {@inheritdoc}
   */
  public static function findAssociatedLocks(string $shared_lock_file) {
    return parent::findAssociatedLocks($shared_lock_file);
  }

  /**
   * {@inheritdoc}
   */
  public static function lockSchema(
    string $schema_name,
    string $reference_file = '',
    bool $exclusive = TRUE,
    string $db_name = ''
  ) {
    return parent::lockSchema($schema_name, $reference_file, $exclusive, $db_name);
  }

  /**
   * {@inheritdoc}
   */
  public function getActionLockName(string $action, array $schema_names) {
    return parent::getActionLockName($action, $schema_names);
  }

  /**
   * {@inheritdoc}
   */
  public function lockAction(
    string $action,
    array $schema_names,
    int $lock_expiration = 0,
    bool $nopid = FALSE
  ) {
    return parent::lockAction($action, $schema_names, $lock_expiration, $nopid);
  }

}
