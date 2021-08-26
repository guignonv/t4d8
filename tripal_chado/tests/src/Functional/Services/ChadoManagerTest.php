<?php

namespace Drupal\Tests\tripal_chado\Functional;

use Drupal\Core\Url;
use Drupal\Tests\BrowserTestBase;
use \Drupal\tripal_chado\Services\ChadoManager;
use \Drupal\Tests\tripal_chado\Functional\Services\Subclasses\UnprotectedChadoManager;

/**
 * Tests for action locks.
 *
 * @group Tripal
 * @group Tripal Chado
 * @group Tripal Services
 */
class ChadoManagerTest extends BrowserTestBase {

  protected $defaultTheme = 'stable';

  /**
   * Modules to enable.
   * @var array
   */
  protected static $modules = ['tripal', 'tripal_chado'];

  /**
   * Tests sanitizeDbName() method.
   *
   * @group chado-manager
   */
  public function testSanitizeDbName() {

    $db_name = strtolower(
      \Drupal::database()->getConnectionOptions()['database']
    );

    $sanitized_name = UnprotectedChadoManager::sanitizeDbName();
    $this->assertEquals($db_name, $sanitized_name);

    $sanitized_name = UnprotectedChadoManager::sanitizeDbName('"test_DB-Name"');
    $this->assertEquals('test_dbname', $sanitized_name);
  }

  /**
   * Tests getLockFilePath() method.
   *
   * @group chado-manager
   */
  public function testGetLockFilePath() {
    $db_name = strtolower(
      \Drupal::database()->getConnectionOptions()['database']
    );

    $lock_file_path = UnprotectedChadoManager::getLockFilePath('action-chado_schema1-chado_schema2');
    $this->assertStringEndsWith("cm_$db_name-action-chado_schema1-chado_schema2.lock", $lock_file_path);

    $lock_file_path = UnprotectedChadoManager::getLockFilePath('action-chado_schema1-chado_schema2', '"test_DB-Name"');
    $this->assertStringEndsWith("cm_test_dbname-action-chado_schema1-chado_schema2.lock", $lock_file_path);
  }

  /**
   * Tests lockSchema() method.
   *
   * @group chado-manager
   */
  // public function testLockSchema() {
  //   $locked = UnprotectedChadoManager::lockSchema('chado_schema1', realpath(__FILE__));
  //   $this->assertTrue($locked);
  // }

  /**
   * Tests lockLockForModifications() method.
   *
   * @group chado-manager
   */
  public function testLockLockForModifications() {
    $lock_file_path = UnprotectedChadoManager::lockLockForModifications('action-chado_schema1-chado_schema2');
    $locked = UnprotectedChadoManager::lockLockForModifications($lock_file_path);
    $this->assertTrue($locked);
  }

  /**
   * Tests lock system.
   *
   * @group chado-manager
   */
  public function testLocks() {

    $db_name = strtolower(
      \Drupal::database()->getConnectionOptions()['database']
    );
    $lock_path =
      \Drupal\Component\FileSystem\FileSystem::getOsTemporaryDirectory()
      . '/'
    ;

    $chado_manager = new UnprotectedChadoManager();
    $lock_name = $chado_manager->lockAction('testcm', ['chado_schema1' => TRUE, 'chado_schema2' => FALSE]);
    $this->assertNotFalse($lock_name, 'Action lock acquired.');
    // Check lock files are generated:
    // -action lock
    $this->assertFileExists(
      $lock_path . "cm_$db_name-testcm-chado_schema1-chado_schema2.lock",
      'Action lock exists.'
    );
    // -schema 1 lock
    $this->assertFileExists(
      $lock_path . "cm_$db_name-chado_schema1.lock",
      'Schema 1 lock exists.'
    );
    // -schema 2 shared lock
    $this->assertFileExists(
      $lock_path . "cm_$db_name-chado_schema2.lock",
      'Schema 2 shared lock exists.'
    );
    // -schema 2 own lock
    // -no modification lock
    $this->assertFileNotExists(
      $lock_path . "cm_$db_name-testcm-chado_schema1-chado_schema2.lock.lock",
      'No schema action lock lock.'
    );
    $this->assertFileNotExists(
      $lock_path . "cm_$db_name-chado_schema1.lock.lock",
      'No schema 1 lock lock.'
    );
    $this->assertFileNotExists(
      $lock_path . "cm_$db_name-chado_schema2.lock.lock",
      'No schema 2 lock lock.'
    );

    // /tmp/cm_$db_name-chado_schema1.lock
    $chado_manager->releaseActionLock($lock_name);
    // Make sure all locks have been removed.
    // -action lock
    $this->assertFileNotExists(
      $lock_path . "cm_$db_name-testcm-chado_schema1-chado_schema2.lock",
      'Action lock removed.'
    );
    // -schema 1 lock
    $this->assertFileNotExists(
      $lock_path . "cm_$db_name-chado_schema1.lock",
      'Schema 1 lock removed.'
    );
    // -schema 2 shared lock
    $this->assertFileNotExists(
      $lock_path . "cm_$db_name-chado_schema2.lock",
      'Schema 2 shared lock removed.'
    );
    // -schema 2 own lock
    // -no modification lock
    $this->assertFileNotExists(
      $lock_path . "cm_$db_name-testcm-chado_schema1-chado_schema2.lock.lock",
      'No schema action lock lock.'
    );
    $this->assertFileNotExists(
      $lock_path . "cm_$db_name-chado_schema1.lock.lock",
      'No schema 1 lock lock.'
    );
    $this->assertFileNotExists(
      $lock_path . "cm_$db_name-chado_schema2.lock.lock",
      'No schema 2 lock lock.'
    );



    // Create a temporary test Chado schema.

    // Make sure a Chado schema exists.
    //
    // $schema_name = 'chado';
    // $lock_status = chadoManager::isSchemaLocked($schema_name);
    // lockSchema($schema_name, $reference_file, $exclusive);
    // $this->assertTrue(FALSE, 'Good.');

  }

}
