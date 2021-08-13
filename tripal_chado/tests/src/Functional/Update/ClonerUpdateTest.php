<?php

namespace Drupal\Tests\tripal_chado\Update;

use Drupal\FunctionalTests\Update\UpdatePathTestBase;
use Drupal\Core\Database\Database;

/**
 * Provides tests for the PostgreSQL schema cloning function update.
 *
 * @group Tripal
 * @group Tripal Chado
 * @group Tripal Database
 * @group Update
 */
class ClonerUpdateTest extends UpdatePathTestBase {

  /**
   * {@inheritdoc}
   */
  protected function setDatabaseDumpFiles() {
    // Note that contributed modules must use an absolute path of
    // DRUPAL_ROOT . '/core/modules/system/tests/fixtures/update/drupal-8.bare.standard.php.gz'
    // to drupal-8.bare.standard.php.gz, because the relative path to core in
    // the testbot is not guaranteed to be the same as what you use on your site.
    // If however you are writing a core test residing in (for example)
    // /core/modules/foo/src/Tests/Update, a relative path of
    // __DIR__ . '/../../../../system/tests/fixtures/update/drupal-8.bare.standard.php.gz'
    // is preferred.
    $this->databaseDumpFiles = [
      DRUPAL_ROOT . '/core/modules/system/tests/fixtures/update/drupal-9.0.0.bare.standard.php.gz',
      // __DIR__ . '/../../../fixtures/update/t4d9.2-chado_clean_install.php.gz',
    ];
  }

  /**
   * Test cloning procedures installation.
   *
   * This test makes sure the 2 PostgreSQL procedures tripal_get_table_ddl() and
   * tripal_clone_schema() are added to Drupal schema during the
   * tripal_chado_update_9101() update.
   *
   * @covers tripal_chado_update_9101()
   */
  public function testUpdateHook9101() {
    $connection = Database::getConnection();
    
    // We can't assert functions are not there in tests as we are testing on
    // a different schema but on the same database that has the functions
    // already. So code below is comment but here to show we thought about it.
    // // Check procedures are not there already.
    // // Procedure tripal_get_table_ddl(character varying,character varying,boolean).
    // // No "{}" around table names since we are using PostgreSQL system tables.
    // $sql_query = "
    //   SELECT p.oid::regprocedure AS \"proc\"
    //   FROM pg_proc p 
    //     JOIN pg_namespace n ON p.pronamespace = n.oid 
    //   WHERE
    //     n.nspname = current_schema()
    //     AND proname = 'tripal_get_table_ddl'
    //     AND pg_get_function_identity_arguments(p.oid) ~* '^\\w+ character varying, \\w+ character varying, \\w+ boolean\$'
    //   ;"
    // ;
    // $func_there = $connection->query($sql_query)->fetch();
    // $this->assertFalse($func_there);
    // 
    // // Procedure tripal_clone_schema(text,text,boolean,boolean).
    // $sql = "
    //   SELECT p.oid::regprocedure
    //   FROM pg_proc p 
    //     JOIN pg_namespace n ON p.pronamespace = n.oid 
    //   WHERE
    //     n.nspname = current_schema()
    //     AND proname = 'tripal_clone_schema'
    //     AND pg_get_function_identity_arguments(p.oid) ~* '^\\w+ text, \\w+ text, \\w+ boolean, \\w+ boolean\$'
    //   ;"
    // ;
    // $func_there = $connection->query($sql_query)->fetch();
    // $this->assertFalse($func_there);

    // Run the updates.
    $this->runUpdates();

    // Test tripal_chado_update_9101.
    // Check if the 2 procedures have been added.
    // Procedure tripal_get_table_ddl(character varying,character varying,boolean).
    $sql_query = "
      SELECT p.oid::regprocedure AS \"proc\"
      FROM pg_proc p 
        JOIN pg_namespace n ON p.pronamespace = n.oid 
      WHERE
        n.nspname = current_schema()
        AND proname = 'tripal_get_table_ddl'
        AND pg_get_function_identity_arguments(p.oid) ~* '^\\w+ character varying, \\w+ character varying, \\w+ boolean\$'
      ;"
    ;
    $func_there = $connection->query($sql_query)->fetch();
    $this->assertNotFalse($func_there);
    
    // Procedure tripal_clone_schema(text,text,boolean,boolean).
    $sql = "
      SELECT p.oid::regprocedure
      FROM pg_proc p 
        JOIN pg_namespace n ON p.pronamespace = n.oid 
      WHERE
        n.nspname = current_schema()
        AND proname = 'tripal_clone_schema'
        AND pg_get_function_identity_arguments(p.oid) ~* '^\\w+ text, \\w+ text, \\w+ boolean, \\w+ boolean\$'
      ;"
    ;
    $func_there = $connection->query($sql_query)->fetch();
    $this->assertNotFalse($func_there);
  }
}
