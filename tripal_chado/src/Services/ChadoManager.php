<?php

namespace Drupal\tripal_chado\Services;

use \Drupal\tripal_chado\api\ChadoSchema;

/**
 * Provides helper methods for managing actions on Chado schemas.
 *
 * This parent class must be used to implement action (services) on Chado
 * schemas. The main role of this class is to provide a lock mecanism that would
 * avoid concurrent actions on a same schema. It also provides a mecanism to
 * get progress on actions performed by another instance as long as the action
 * lock identifier is known.
 *
 * When an action needs to modify one or more Chado scheam, the extending class
 * performing the action will need to call `$this->lockAction()` to prevent
 * concurrent actions from modifying the same schemas. `lockAction()` will
 * acquire a lock on each requested schema. If one of them cannot be locked, all
 * the other acquired locks will be released and an error will be thrown telling
 * the action cannot be performed because of other locks.
 * Existing but unused locks (the process using it did not release it), will be
 * automatically removed when a new lock is required to avoid a never ending
 * locks issue.
 * A lock on a schema is composed by a symbolic link in
 * FileSystem::getOsTemporaryDirectory() directory with a special name that
 * is unique for a given schema(1). All the schema lock symbolic links point to
 * a file that describes the action performed. That file is just a text file
 * that begins with the operating system process identifier (PID) of the process
 * executing the action. Therefore, the presence of the process can be checked
 * by others to see if some locks have been "lost" and could be freed. The next
 * line is an expiration timestamp after which the lock can be released.
 * For locks that are not process-specific (ie. PID=1), it's the only way to
 * check for an expiration that can release the lock. The next line will begin
 * with 'ro: ' followed by a coma-separated list of schema the action uses in
 * read-only mode. The next line will begin with 'w: ' followed by a
 * coma-separated list of schema the action can modify. Next lines are action
 * specific, can be used by other processes (in read only) to get some info but
 * are only written by the action process.
 * (1) Symbolic links are unique for modified schemas but since schemas only
 * used for reading operations will not be modified, they can be shared across
 * several actions that just read them. Therefore, they may have more than one
 * lock symbolic link that identify them: those links will share the same prefix
 * followed by a random number. The initial link for shared schema in reading
 * mode will point to ChadoManager.php file and it will have at least a second
 * lock file (same prefix but a different suffix) for the first action that
 * requires it. The lock release mecaninsm will not release the first lock until
 * any other read locks is present.
 *
 * Example: we have two action runing on the database 'genome'. The first one is
 * a cloning action that clones "chado_priv" schema into "chado_pub" schema and
 * another action that works in read-only mode on "chado_priv" schema to compute
 * some statistics. The lock file system will be similar to something like this
 * while the 2 tasks are running in parallel:
 * ```
 * # Lock file containg cloning action info.
 * /tmp/cm_genome-clone-chado_priv-chado_pub.lock
 * # Lock file containg statistics action info.
 * /tmp/cm_genome-stat_91748703-chado_priv.lock
 * # The 'chado_pub' exclusive lock symlink for cloning action.
 * /tmp/cm_genome-chado_pub.lock --> cm_genome-clone-chado_priv-chado_pub.lock
 * # The 'chado_priv' shared lock symlink for all read-only actions.
 * /tmp/cm_genome-chado_priv.lock --> [somepath]/ChadoManager.php
 * # The 'chado_priv' shared lock symlink for cloning action.
 * /tmp/cm_genome-chado_priv-57426012.lock --> cm_genome-clone-chado_priv-chado_pub.lock
 * # The 'chado_priv' shared lock symlink for statistics action.
 * /tmp/cm_genome-chado_priv-57426012.lock --> cm_genome-stat_91748703-chado_priv.lock
 * # Also, only when the 'chado_priv' shared lock is created and when a second
 * # action is asking to use that shared lock, the following file will briefly
 * # appear and be removed once the lock has been acquired (or released):
 * /tmp/cm_genome-chado_priv.lock.lock --> cm_genome-chado_priv.lock
 *
 * Note: in this example, the statistics action can be run several times on a
 * same schema (let's say it's a task that can compute statisctics on a single
 * subset of data and the schema holds several subsets) and therefore it can use
 * different lock name for the same action but with a shared prefix (ie.
 * 'stat_').
 * Here, 91748703 and 57426012 are randomly generated numbers for unicity.
 *
 * Most functions working on schema locks are static because they should not be
 * related to a specific instance of a manager and share the same behavior.
 * Most functions related to action locks are non-static member functions
 * because they are specific to a given action and depending on the action, they
 * may behave differently to generate their lock names and manage them.
 */
class ChadoManager {

  /**
   * Maximum number of tries to lock somehting.
   */
  protected const MAX_ATTEMPTS = 5;

  /**
   * Time to wait between attempts in seconds.
   */
  protected const ATTEMPTS_WAIT_TIME = 5;

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
  protected $locks = [];

  /**
   * Logger used to log and report messages.
   *
   * @var object
   */
  protected $logger;

  /**
   * Database name (used for lock names).
   *
   * @var string
   */
  protected $db_name;

  /**
   * Returns a sanitized version of the database name.
   *
   * @param string $db_name
   *   Name of the database to use. Default: Drupal default database.
   */
  protected static function sanitizeDbName(string $db_name = '') {
    // Get default database (real) name.
    $default_db_name = strtolower(
      \Drupal::database()->getConnectionOptions()['database']
    );
    $db_name = strtolower($db_name);
    if (empty($db_name) || ('default' == $db_name)) {
      $db_name = $default_db_name;
    }
    return preg_replace('/\W+/', '', $db_name);
  }

  /**
   * Gets the lock file path (existing or not).
   *
   * @param string $lock_name
   *   The lock name.
   * @param string $db_name
   *   Name of the database to use.
   *
   * @return string
   *   A file path to the symbolic link that prevents the lock being re-used.
   */
  protected static function getLockFilePath(
    string $lock_name,
    string $db_name = ''
  ) {
    $db_name = static::sanitizeDbName($db_name);
    $file_path =
      \Drupal\Component\FileSystem\FileSystem::getOsTemporaryDirectory()
      . '/cm_'
      . $db_name
      . '-'
      . $lock_name
      . '.lock'
    ;
    return $file_path;
  }

  /**
   * Gets the lock file path (existing or not).
   *
   * @param string $lock_file
   *   The lock file path.
   *
   * @return bool
   *   FALSE if not locked, the file name of the "lock lock" symlink.
   */
  protected static function lockLockForModifications(string $lock_file) {
    $attempts = static::MAX_ATTEMPTS;
    $lock_lock_file = $lock_file . '.lock';
    while ($attempts
        && (@symlink($lock_file, $lock_lock_file) === FALSE)
    ) {
      if (--$attempts) {
        sleep(static::ATTEMPTS_WAIT_TIME);
      }
    }
    return $attempts ? $lock_lock_file : FALSE;
  }

  /**
   * Check if a lock file has expired.
   *
   * @param string $lock_filepath
   *   Full path to the lock file.
   *
   * @return bool
   *   TRUE if expired (file removed), FALSE otherwise.
   */
  protected static function isLockFileExpired(string $lock_filepath) {
    $expired = FALSE;
    try {
      // Check for lock expiration.
      $lock_data = file($lock_filepath);
      // Less than 2 lines in the lock file: corrupted data.
      if (2 > count($lock_data)) {
        throw new \Exception("Corrupted lock file '$lock_filepath'! Please remove that file manualy.");
      }
      $lock_pid = $lock_data[0];
      $lock_expiration = $lock_data[1];
      // PID above 1: PID-managed lock (may not work under Windows server).
      // Otherwise, check time.
      if ((1 < $lock_pid && !file_exists("/proc/$lock_pid"))
          || ((0 < $lock_expiration) && (time() >= $lock_expiration))
      ) {
        // Process not there or time expired, remove lock.
        if (unlink($lock_filepath)) {
          // We could also remove symlinks pointing to this file as well here but
          // we will handle that task elsewhere.
          $expired = TRUE;
        }
        else {
          throw new \Exception("Unable to remove expired lock file '$lock_filepath'! Please remove it manualy.");
        }
      }
      elseif ((1 >= $lock_pid) && (0 >= $lock_expiration)) {
        // Lock not managed by a PID or an expiration time.
        // Get lock file details to check its age.
        $fstat = stat($lock_filepath);
        // If it is older than a week (604800),  warn about it.
        if ($fstat['mtime'] <= (time() - 604800)) {
          // Report to check the lock manualy.
          $logger = \Drupal::logger('tripal_chado');
          $logger->warning("ChadoManager: The lock file '$lock_filepath' is older than a week and has no expiration setting. If you have no Chado action running, you may remove that lock manualy.\n");
        }
      }
    }
    catch (Exception $e) {
      $logger = \Drupal::logger('tripal_chado');
      $logger->error("ChadoManager: " . $e->getMessage());
    }
    return $expired;
  }

  /**
   * Find all lock files related to a shared lock symlink.
   *
   * @param string $shared_lock_file
   *   The file name of the shared lock symlink.
   *
   * @return array
   *   an array of related lock file path.
   */
  protected static function findAssociatedLocks(
    string $shared_lock_file
  ) {
    // Find all related locks.
    // Extract lock file prefix.
    $lock_prefix = preg_replace('/^.*\/|\.lock$/', '', $shared_lock_file);
    $lock_file_list = [];
    $lock_directory = \Drupal\Component\FileSystem\FileSystem::getOsTemporaryDirectory();
    // Look for other files in the lock directory.
    $file_list = scandir($lock_directory, SCANDIR_SORT_NONE);
    foreach ($file_list as $filename) {
      // Nb.: $lock_prefix should not contain regex special characters by
      // construction (sanitized name) and does not need to be regex-escaped.
      // We want to capture other locks as generated by lockSchema().
      $lock_re = '/' . $lock_prefix . '(-\d{8})\.lock$/';
      if (is_link("$lock_directory/$filename")
          && (preg_match($lock_re, $filename, $match))
      ) {
        // Here, substr($match[1], 1) contains the numeric identifer.
        // Keep full symlink path.
        $lock_file_list[] = "$lock_directory/$filename";
      }
    }
    return $lock_file_list;
  }

  /**
   * Tells if a given schema is currently locked.
   *
   * @param string $schema_name
   *   The schema name to check.
   *
   * @return mixed
   *   If not in use, returns FALSE, otherwise returns the type of lock. It can
   *   be either 'ro' or 'w'.
   */
  public static function isSchemaLocked(
    string $schema_name,
    string $db_name = ''
  ) {
    $is_locked = FALSE;
    $lock_file = static::getLockFilePath($schema_name, $db_name);

    try {
      // First, we check if a lock file exists for that schema.
      if (file_exists($lock_file)) {
        // At first look, schema appears to be locked.
        $is_locked = 'w';
        // Make sure the lock file is a symlink, otherwise soemthing unexpected
        // occurred!
        if (!is_link($lock_file)) {
          throw new \Exception("A reserved lock name is used by the file '$lock_file'! Please remove that file manualy and retry.");
        }
        // Get link target which should be either an action lock file (.lock) for
        // exclusive locks or a PHP script file for a shared lock.
        $link_target = realpath($lock_file);
        if (!$link_target) {
          // The target does not exists, the symlink can be removed.
          if (unlink($lock_file)) {
            $is_locked = FALSE;
          }
          else {
            throw new \Exception("Unable to remove expired symlink '$lock_file'! Please remove that symlink manualy and retry.");
          }
        }
        elseif (preg_match('/\.lock$/', $link_target)) {
          if (static::isLockFileExpired($link_target) && unlink($lock_file)) {
            $is_locked = FALSE;
          }
          else {
            throw new \Exception("Unable to remove expired symlink '$lock_file'! Please remove it manualy.");
          }
        }
        else {
          // This is a shared lock.
          $is_locked = 'ro';
          // Tries to lock current shared lock for (possible) modifications.
          if ($mod_lock = static::lockLockForModifications($lock_file)) {
            try {
              // Find all related locks.
              $lock_file_list = static::findAssociatedLocks($lock_file);
              // Now check every action to see if its lock expired.
              $expired = TRUE;
              while ($expired && ($other_lock_file = array_pop($lock_file_list))) {
                try {
                  $link_target = realpath($other_lock_file);
                  if (!$link_target || static::isLockFileExpired($link_target)) {
                    // The target expired, the symlink can be removed.
                    if (!unlink($other_lock_file)) {
                      $expired = FALSE;
                      throw new \Exception("Unable to remove expired symlink '$other_lock_file'! Please remove that symlink manualy.");
                    }
                  }
                  else {
                    // We got at least one unexpired action. Stop here.
                    $expired = FALSE;
                  }
                }
                catch (Exception $e) {
                  $logger = \Drupal::logger('tripal_chado');
                  $logger->warning("ChadoManager: " . $e->getMessage());
                }
              }
              // All expired?
              if ($expired) {
                $is_locked = FALSE;
              }
            }
            catch (Exception $e) {
              // Try cleanup.
              if (!unlink($mod_lock)) {
                throw new \Exception(
                  $e->getMessage()
                  . "Unable to remove modification lock '$mod_lock'! Please remove it manualy and retry."
                );
              }
              else {
                // Rethrow
                throw $e;
              }
            }

            // Release lock modification lock.
            if (!unlink($mod_lock)) {
              throw new \Exception("Unable to remove modification lock '$mod_lock'! Please remove it manualy and retry.");
            }
          }
          else {
            // Could not check shared lock status, assume locked 'ro'.
            throw new \Exception("Unable to get a modification lock for lock file '$lock_file'!");
          }
        }
      }
    }
    catch (Exception $e) {
      $logger = \Drupal::logger('tripal_chado');
      $logger->error("ChadoManager: " . $e->getMessage());
    }

    return $is_locked;
  }

  /**
   * Tries to lock the given schema.
   *
   * @param string $schema_name
   *   The name of the schema to lock (the schema may not exist).
   * @param string $reference_file
   *   The full path to the file pointed by the link. Must correspond to an
   *   existing file and must not be empty.
   * @param bool $exclusive
   *   If FALSE, the lock will be for reading and can be shared, otherwise the
   *   lock will be exclusive.
   *
   * @return bool
   *   TRUE if the lock has been acquired, FALSE otherwise.
   */
  protected static function lockSchema(
    string $schema_name,
    string $reference_file = '',
    bool $exclusive = TRUE,
    string $db_name = ''
  ) {
    $success = FALSE;

    // Check $reference_file.
    if (empty($reference_file) || !file_exists($reference_file)) {
      throw new \Exception("Invalid reference lock file '$reference_file' (empty or not existing).");
    }

    // Get schema lock file name.
    $lock_file = static::getLockFilePath($schema_name, $db_name);

    try {
      if (!$exclusive) {
        // Shareable schema, check if free.
        $lock_status = static::isSchemaLocked($schema_name, $db_name);
        if ('w' == $lock_status) {
          // Locked in exclusive mode. Can't lock in shared mode.
          throw new \Exception("Unable to lock schema. Schema already locked by another action.");
        }
        else {
          // Acquire a lock on "schema lock modification".
          if ($mod_lock = static::lockLockForModifications($lock_file)) {
            try {
              // Try to create shared lock symlink.
              // First get a shared reference file.
              $module_handler = \Drupal::service('module_handler');
              $fs = \Drupal::service('file_system'); 
              $tc_path = $module_handler->getModule('tripal_chado')->getPath();
              $shared_reference_file =
                $fs->realpath($tc_path . '/src/Services/ChadoManager.php')
              ;
              if (!file_exists($shared_reference_file)) {
                throw new \Exception("Unable to locate lock reference file 'ChadoManager.php' at '$shared_reference_file'.");
              }
              // Create a shared lock if not existing.
              if (!file_exists($lock_file)
                  && (@symlink($shared_reference_file, $lock_file) === FALSE)
              ) {
                throw new \Exception("Unable to create schema shared lock file '$lock_file' poiting to '$shared_reference_file'.");
              }
              else {
                // Schema shared lock exists.
                // Create action-specific lock.
                $attempts = 50;
                do {
                  // Generates a suffix with dash followed by 8 digits.
                  $lock_id = mt_rand(10000000, 99999999);
                  $action_schema_lock_file = preg_replace('/\.lock$/', "-$lock_id.lock", $lock_file);
                } while ($attempts-- && (file_exists($action_schema_lock_file)));

                // Add instance specific lock.
                if (@symlink($reference_file, $action_schema_lock_file) === FALSE) {
                  throw new \Exception("Failed to create lock symlink '$action_schema_lock_file' for action shared lock on schema '$schema_name'.");
                }
                $success = TRUE;
              }
            }
            catch (Exception $e) {
              // Try cleanup.
              if (!unlink($mod_lock)) {
                throw new \Exception(
                  $e->getMessage()
                  . "Failed to remove lock symlink '$mod_lock'. Please remove it manualy."
                );
              }
              else {
                // Rethrow
                throw $e;
              }
            }
            // Release lock on "schema lock modification".
            if (!unlink($mod_lock)) {
              throw new \Exception("Failed to remove lock symlink '$mod_lock'. Please remove it manualy.");
            }
          }
          else {
            throw new \Exception("Unable to lock schema. Could not get a modification lock symlink for lock '$lock_file'.");
          }
        }
      }
      elseif (!static::isSchemaLocked($schema_name, $db_name)) {
        // Not shared, try to lock schema.
        $attempts = static::MAX_ATTEMPTS;
        while ($attempts
            && (@symlink($reference_file, $lock_file) === FALSE)
        ) {
          if (--$attempts) {
            sleep(static::ATTEMPTS_WAIT_TIME);
          }
        }
        if ($attempts) {
          $success = TRUE;
        }
        else {
          throw new \Exception("Unable to lock schema. Failed to create lock symlink '$lock_file' for '$reference_file'.");
        }
      }
    }
    catch (Exception $e) {
      $logger = \Drupal::logger('tripal_chado');
      $logger->error("ChadoManager: " . $e->getMessage());
    }

    return $success;
  }

  /**
   * Releases a lock on a given schema.
   *
   * @param string $schema_name
   *   The name of the schema to unlock.
   * @param string $lock_name
   *   Name of the lock to remove. If empty, it will try
   *   `$this->getActionLockName('new', [$schema_name])` as default. If no lock
   *   matches, nothing will be removed and FALSE will be returned.
   *
   * @return bool
   *   TRUE if successful, FALSE if not.
   */
  public static function releaseSchemaLock(string $schema_name, string $lock_name = '', string $db_name = '') {
    $sucess = FALSE;
    // Get schema lock file name.
    if (empty($lock_name)) {
      $lock_name = $this->getActionLockName('new', [$schema_name]);
    }
    $reference_file = static::getLockFilePath($lock_name, $db_name);
    $real_reference_file = realpath($reference_file);
    $schema_lock_symlink = static::getLockFilePath($schema_name, $db_name);

    try {
      // Make sure we work on a symlink lock.
      if (file_exists($schema_lock_symlink)) {
        if (!is_link($schema_lock_symlink)) {
          throw new \Exception("Schema lock '$schema_lock_symlink' is not a symlink. Please remove that file manualy.");
        }
        // Get target to check for shared lock.
        $link_target = realpath($schema_lock_symlink);
        if ($real_reference_file != $link_target) {
          // Shared lock, acquire a lock on "schema lock modification".
          if ($mod_lock = static::lockLockForModifications($schema_lock_symlink)) {
            try {
              // Find all schema related locks.
              $lock_file_list = static::findAssociatedLocks($schema_lock_symlink);
              $lock_in_use = 0;
              while ($other_lock_file = array_pop($lock_file_list)) {
                try {
                  $other_link_target = realpath($other_lock_file);
                  // Check target.
                  if ($other_link_target == $real_reference_file) {
                    // We got the lock symlink used for sharing. Remove used lock.
                    if (!unlink($other_lock_file)) {
                      throw new \Exception("Unable to remove '$other_lock_file' lock. Please remove that symlink manualy.");
                    }
                    $success = TRUE;
                  }
                  elseif ($other_link_target) {
                    // Other refered file exists and might use the lock.
                    ++$lock_in_use;
                  }
                  else {
                    // No target, remove lock symlink (expired).
                    if (!unlink($other_lock_file)) {
                      throw new \Exception("Unable to remove '$other_lock_file' unused lock. Please remove that symlink manualy.");
                    }
                  }
                }
                catch (Exception $e) {
                  $logger = \Drupal::logger('tripal_chado');
                  $logger->warning("ChadoManager: " . $e->getMessage());
                }
              }
              // Check if the shared lock is still in use.
              if (!$lock_in_use) {
                // Shared lock unused, remove it.
                if (!unlink($schema_lock_symlink)) {
                  throw new \Exception("Unable to remove '$schema_lock_symlink' unused schema lock. Please remove that symlink manualy.");
                }
              }
            }
            catch (Exception $e) {
              // Try cleanup.
              if (!unlink($mod_lock)) {
                throw new \Exception(
                  $e->getMessage()
                  . "Failed to remove modification lock '$mod_lock'. Please remove it manualy."
                );
              }
              else {
                // Rethrow
                throw $e;
              }
            }

            // Release lock on "schema lock modification".
            if (!unlink($mod_lock)) {
              throw new \Exception("Failed to remove modification lock '$mod_lock'. Please remove it manualy.");
            }
            $success = TRUE;
          }
          else {
            // Failed to be able to modify shared link.
            throw new \Exception("Unable to remove '$schema_lock_symlink' lock: could not modify shared lock.");
          }
        }
        else {
          // Exclusive lock.
          $sucess = unlink($schema_lock_symlink);
        }
      }
      else {
        // Symlink already removed, no lock.
        $sucess = TRUE;
      }
    }
    catch (Exception $e) {
      $logger = \Drupal::logger('tripal_chado');
      $logger->error("ChadoManager: " . $e->getMessage());
    }

    return $sucess;
  }

  /**
   * ChadoManager constructor.
   *
   * This constructor should be called by extending classes at the begining of
   * their own constructors (`parent::__construct();`) in order to support
   * future class evolutions .
   *
   * @param array $arguments
   *   An array of parameters required to perform the management action. This
   *   parameter should be checked by extending classes.
   * @param string $db_name
   *   Name of current database. Of omitted, default Drupal database will be
   *  used.
   *
   * @throws \InvalidArgumentException
   *   Thrown by extending classes when an argument is missing or invalid.
   */
  public function __construct(array $arguments = [], string $db_name = '') {
    // Initialize the logger.
    $this->logger = \Drupal::logger('tripal_chado');

    // Get database name.
    $this->db_name = static::sanitizeDbName($db_name);
  }

  /**
   * Removes remaining locks.
   */
  function __destruct() {
    // Release used locks if not released already.
    foreach ($this->locks as $lock_name => $schemas) {
      $this->releaseActionLock($lock_name);
    }
  }

  /**
   * Performs the required action.
   *
   * This method must be overriden by extending classes. This parent method
   * should not be called as it throws an error.
   *
   * @return bool
   *   TRUE if the action was performed with success and FALSE otherwise. In
   *   some cases, exceptions can also be thrown in order to report failures.
   */
  public function performAction() {
    throw new \Exception("Not implemented. This method must be implemented in extending classes.");
  }

  /**
   * Returns the percent of progress of current action.
   *
   * This method must be overriden by extending classes.
   *
   * @return float
   *   A value between 0 and 1, 1 meaning the action is complete.
   */
  public function getProgress() {
    throw new \Exception("Not implemented. This method must be implemented in extending classes.");
  }

  /**
   * Returns a string describing current status of the performed action.
   *
   * It should be overriden by extending classes.
   *
   * @return string
   *   A localized description.
   */
  public function getStatus() {
    $progress = $this->getProgress();
    if (0 >= $progress) {
      $status = t('Not started yet.');
    }
    elseif (1 <= $progress) {
      $status = t('Done.');
    }
    else {
      $status = t('In progress');
    }
    return $status;
  }

  /**
   * Returns the logger used by this instance.
   *
   * @return object
   *   Instance logger.
   */
  public function getLogger() {
    return $this->logger;
  }

  /**
   * Returns the name of an action lock.
   *
   * @param string $action
   *   The name of the action performed.
   * @param array $schema_names
   *   A list of schema names that are used by the action. Those names are
   *   passed as keys and their associated boolean will tell if the schema must
   *   have an exclusive lock for writing (TRUE) or if it can be shared (FALSE)
   *   for reading.
   *
   * @return string
   *   The complete action lock identifier.
   */
  protected function getActionLockName(string $action, array $schema_names) {
    // Sanitize action name.
    $action = preg_replace('/\W+/', '_', strtolower($action));
    $action = preg_replace('/^_+|_+$/', '', $action);
    if (empty($action)) {
      // Use a default name if empty.
      $action = 'action';
    }
    // Prepare schema names.
    $schema_list = array_map(
      function ($schema_name) {
        $schema_name = str_replace('"', '', strtolower($schema_name));
        if ($error = ChadoSchema::isInvalidSchemaName($schema_name)) {
          throw new \Exception("Invalid schema name '$schema_name': $error");
        }
        // Sanitize schema name for the final lock name.
        $schema_name = preg_replace('/\W/', '_', $schema_name);
        return $schema_name;
      },
      array_keys($schema_names)
    );
    // Sort in order to always generate a same name regardless schema order.
    sort($schema_list);
    // Concatenate schema names with a dash as it can't be part of a schema
    // name but can be used in file names.
    $lock_name =
      $action
      . '-'
      . implode('-', $schema_list)
    ;

    return $lock_name;
  }

  /**
   * Locks the given schemas for a given action.
   *
   * @param string $action
   *   The name of the action performed.
   * @param array $schema_names
   *   A list of schema names that will be used by the action. Those names are
   *   passed as keys and their associated boolean will tell if the schema must
   *   have an exclusive lock for writing (TRUE) or if it can be shared (FALSE)
   *   for reading.
   * @param int $lock_expiration
   *   A Unix timestamp after which the lock can be considered expired and
   *   released. If not set or below current timestamp, it will be set to
   *   current timestamp + 1 day.
   * @param bool $nopid
   *   If set to TRUE, the process ID used will be '1', meaning that the lock is
   *   not related to a process.
   *
   * @return mixed
   *   FALSE if it failed to get a lock and the complete action lock identifier
   *   otherwise.
   */
  protected function lockAction(
    string $action,
    array $schema_names,
    int $lock_expiration = 0,
    bool $nopid = FALSE
  ) {
    $lock_success = FALSE;
    // Get a lock name.
    $lock_name = $this->getActionLockName($action, $schema_names);
    $lock_file = static::getLockFilePath($lock_name);
    $logger = \Drupal::logger('tripal_chado');

    try {
      // Create a lock file for the action.
      if ($mod_lock = static::lockLockForModifications($lock_file)) {
        try {
          // We can manage the lock.
          // Check if the lock file already exists.
          if (!file_exists($lock_file)
              || (static::isLockFileExpired($lock_file))
          ) {
            // Create lock file.
            $fh = fopen($lock_file, 'w');
            // Stores process ID.
            fwrite($fh, (getmypid() ?: '1') . "\n");
            // Stores expiration time. Default: now + 1 day (1 day = 86400s).
            fwrite($fh, ($lock_expiration ?: time() + 86400) . "\n");
            $ro_schemas = [];
            $w_schemas = [];
            // Sort schemas.
            foreach ($schema_names as $schema_name => $exclusive) {
              if ($exclusive) {
                $w_schemas[] = $schema_name;
              }
              else {
                $ro_schemas[] = $schema_name;
              }
            }
            sort($ro_schemas);
            sort($w_schemas);
            // Stores read-only schemas.
            fwrite($fh, 'ro: ' . implode(',', $ro_schemas). "\n");
            // Stores write schemas.
            fwrite($fh, 'w: ' . implode(',', $w_schemas). "\n");
            fclose($fh);
            // Try to lock each schemas.
            $locked_schemas = [];
            try {
              foreach ($schema_names as $schema_name => $exclusive) {
                if (static::lockSchema($schema_name, $lock_file, $exclusive)) {
                  $locked_schemas[] = $schema_name;
                }
                else {
                  throw new \Exception("Unable to lock schema '$schema_name' (exclusive mode: $exclusive).");
                }
              }
              // Return the lock name.
              $lock_success = $lock_name;
              $this->locks[$lock_name] = $locked_schemas;
            }
            catch (Exception $e) {
              $logger->error("ChadoManager: " . $e->getMessage());
              // If it failed, remove previous schema locks and return FALSE
              foreach ($locked_schemas as $locked_schema) {
                try {
                  if (!static::releaseSchemaLock($locked_schema, $lock_name)) {
                    throw new \Exception("Unable to unlock schema '$locked_schema' for action '$action' ($lock_name).");
                  }
                }
                catch (Exception $e) {
                  $logger->error("ChadoManager: " . $e->getMessage());
                }
              }
              if (!unlink($lock_file)) {
                throw new \Exception("Unable to remove lock file '$lock_file'. Please remove it manualy.");
              }
            }
          }
          else {
            // Unable to create a lock file.
            throw new \Exception("Unable to lock action '$action'. Another process appears to be performing that action.");
          }
        }
        catch (Exception $e) {
          // Try cleanup.
          if (!unlink($mod_lock)) {
            throw new \Exception(
              $e->getMessage()
              . "Unable to remove modification lock '$mod_lock'. Please remove it manualy."
            );
          }
          else {
            // Rethrow
            throw $e;
          }
        }
        // Release lock modification lock.
        if (!unlink($mod_lock)) {
          throw new \Exception("Unable to remove modification lock '$mod_lock'. Please remove it manualy.");
        }
      }
      else {
        // Unable to manage the lock file.
        throw new \Exception("Unable to lock action '$action' (another concurrent job is currently managing that action).");
      }
    }
    catch (Exception $e) {
      $logger = \Drupal::logger('tripal_chado');
      $logger->error("ChadoManager: " . $e->getMessage());
    }

    return $lock_success;
  }

  /**
   * Gets a unique new name of an unexisting schema and locks it.
   *
   * @param string $schema_prefix
   *   The prefix to use for the new schema name. Must not be empty.
   *
   * @return string
   *   The name of the new schema (not created) or FALSE if it failed.
   */
  public function getNewSchemaLock(string $schema_prefix = 'chado_') {

    // Check prefix.
    if (empty($schema_prefix)) {
      throw new \Exception("Invalid schema prefix (empty).");
    }
    // 56 = 64 (64 = max PostgreSQL schema name length) - 8 (8 = number of
    // randomly generated digits appended to the schema prefix).
    if (56 < strlen($schema_prefix)) {
      throw new \Exception("Invalid schema prefix: prefix too long. It must not exceed 56 characters.");
    }
    $schema_name_issue = ChadoSchema::isInvalidSchemaName($schema_prefix);
    if ($schema_name_issue) {
      throw new \Exception("Invalid schema prefix. $schema_name_issue");
    }

    // Tries to get a new free schema name.
    $attempts = 50;
    do {
      // Generates a 8-random number suffix.
      $lock_id = mt_rand(10000000, 99999999);
      $schema_name = $schema_prefix . $lock_id;
      // Lock that schema in exclusive mode if possible.
      $lock_name = $this->lockAction('new', [$schema_name => TRUE]);
      if (!$lock_name) {
        $lock_id = NULL;
        $schema_name = FALSE;
      }
    } while (($lock_id === NULL) && (--$attempts));

    // If we got a lock, record it.
    if ($lock_name) {
      $this->locks[$lock_name] = [$schema_name];
    }

    return $schema_name;
  }

  /**
   * Releases a lock on a given action.
   *
   * @param string $lock_name
   *   The name of the action lock to unlock.
   *
   * @return bool
   *   TRUE if successful, FALSE if not.
   */
  public function releaseActionLock(string $lock_name) {
    $unlocked = FALSE;
    $lock_file = static::getLockFilePath($lock_name);
    
    try {
      // Create a lock file for the action.
      if ($mod_lock = static::lockLockForModifications($lock_file)) {
        try {
          if (!array_key_exists($lock_name, $this->locks)) {
            throw new \Exception("Lock name not in use '$lock_name'. Available lock names are: '" . implode("', '", array_keys($this->locks)) . "'");
          }
          // Try unlock each action-locked schema.
          foreach ($this->locks[$lock_name] as $schema_name) {
            static::releaseSchemaLock($schema_name, $lock_name);
          }
          // Remove action lock file.
          if (!unlink($lock_file)) {
            throw new \Exception("Unable to remove lock file '$lock_file'. Please remove it manualy.");
          }
        }
        catch (Exception $e) {
          // Try cleanup.
          if (!unlink($mod_lock)) {
            throw new \Exception(
              $e->getMessage()
              . "Unable to remove modification lock '$mod_lock'. Please remove it manualy."
            );
          }
          else {
            // Rethrow
            throw $e;
          }
        }
        if (!unlink($mod_lock)) {
          throw new \Exception("Unable to remove modification lock '$mod_lock'. Please remove it manualy.");
        }
        // Clear lock record.
        unset($this->locks[$lock_name]);
        $unlocked = TRUE;
      }
    }
    catch (Exception $e) {
      $logger = \Drupal::logger('tripal_chado');
      $logger->error("ChadoManager: " . $e->getMessage());
    }

    return $unlocked;
  }

}
