<?php

namespace Drupal\tripal_chado\api;

use Symfony\Component\Yaml\Yaml;
use Drupal\Core\Database\Database;

/**
 * Provides an API for Chado schema.
 *
 * Provides an application programming interface (API) for describing Chado
 * schema and tables. It provides both static and instance methods. Static
 * methods are designed to work regardless any specific Chado schema while
 * instance methods work on a given Chado schema instance specified when the
 * ChadoSchema object is instanciated. Default schema used for instances is
 * 'chado'.
 *
 * If you need the Drupal-style array definition for any table, use the
 * following:
 *
 * @code
 * $chado_schema = new \ChadoSchema();
 * $table_schema = $chado_schema->getTableSchema($table_name);
 * @endcode
 *
 * where the variable $table contains the name of the table you want to
 * retireve.  The getTableSchema method determines the appropriate version of
 * Chado and uses the Drupal hook infrastructure to call the appropriate
 * hook function to retrieve the table schema.
 *
 * Additionally, here are some other examples of how to use this class:
 * @code
 *
 * // Retrieve the schema array for the organism table in chado 1.2
 * $chado_schema = new \ChadoSchema('1.2');
 * $table_schema = $chado_schema->getTableSchema('organism');
 *
 * // Retrieve all chado tables.
 * $chado_schema = new \ChadoSchema();
 * $tables = $chado_schema->getTableNames();
 * $base_tables = $chado_schema->getbaseTables();
 *
 * // Check the feature.type_id foreign key constraint.
 * $chado_schema = new \ChadoSchema();
 * $exists = $chado_schema ->checkFKConstraintExists('feature','type_id');
 *
 * // Check Sequence exists.
 * $chado_schema = new \ChadoSchema();
 * $exists = $chado_schema->checkSequenceExists('organism','organism_id');
 * // Or just check the primary key directly.
 * $compliant = $chado_schema->checkPrimaryKey('organism');
 * @endcode
 */
class ChadoSchema {

  /**
   * Reserved schema name of the Chado schema used for testing.
   */
  public const TEST_SCHEMA_NAME = '_chado_test';

  /**
   * @var string
   *   The current version for this site. E.g. "1.3".
   */
  protected $version = '';

  /**
   * @var string
   *   The name of the schema chado was installed in.
   */
  protected $schema_name = 'chado';

  /**
   * @var array
   *   A description of all tables which should be in the current schema.
   */
  protected $schema = [];

  /**
   * @var object \Drupal
   * Saves the logger.
   */
  protected $logger = NULL;

  /**
   * @var object \Drupal
   * Saves the Drupal database connection.
   */
  protected $connection = NULL;

  /**
   * @var pgsql link resource
   * Saves the direct PostgreSQL database connection.
   */
  protected $pgconnection = NULL;

  /**
   * @var string
   * The default database.
   */
  protected $default_db = NULL;

  /**
   * @var bool
   * Internal flag for testing features.
   */
  protected static $test_mode = FALSE;

  /**
   * Get database connection object.
   *
   * @param string &$db_name
   *   The name of the database. If empty, it will be set to 'default'.
   *
   * @return \Drupal\Core\Database\Connection
   *   A Drupal database connection object.
   */
  protected static function getDatabase(&$db_name) {
    $default_db_name = \Drupal::database()->getConnectionOptions()['database'];
    if (empty($db_name)
        || ('default' == $db_name)
      || ($default_db_name == $db_name)
    ) {
      $db_name = 'default';
      $db = \Drupal::database();
      $db_name = $db->getConnectionOptions()['database'];
    }
    else {
      $db = \Drupal\Core\Database\Database::getConnection('default', $db_name);
    }
    return $db;
  }

  /**
   * Get/set internal test mode.
   *
   * @param bool $test_mode
   *   New test mode value.
   *
   * @return bool
   *   Current test mode status. TRUE means test mode is enabled.
   */
  public static function testMode(?bool $test_mode = NULL) {
    if (isset($test_mode)) {
      ChadoSchema::$test_mode = $test_mode;
    }
    return ChadoSchema::$test_mode;
  }

  /**
   * Check that the given schema name is a valid schema name.
   *
   * @param string $schema_name
   *   The name of the schema to validate.
   *
   * @return string
   *   A string describing the issue in the name or an empty string if the
   *   schema name is valid.
   */
  public static function isInvalidSchemaName($schema_name) {

    $issue = '';
    // Schema name must be all lowercase with no special characters with the
    // exception of underscores and diacritical marks (which can be uppercase).
    // ref.:
    // https://www.postgresql.org/docs/9.5/sql-syntax-lexical.html#SQL-SYNTAX-IDENTIFIERS
    // It should also not contain any space and must not begin with "pg_".
    // Note: capital letter could be used but are silently converted to
    // lowercase by PostgreSQL. Here, we want to avoid ambiguity so we forbid
    // uppercase.
    $schema_name_regex = '/^[a-z_\\xA0-\\xFF][a-z_\\xA0-\\xFF0-9]*$/';
    // Make sure we have a valid schema name.
    if (0 === preg_match($schema_name_regex, $schema_name)) {
      $issue = t(
        'The schema name must not begin with a number and only contain lower case letters, numbers, underscores and diacritical marks.'
      );
    }
    elseif (0 === strpos($schema_name, 'pg_')) {
      $issue = t(
        'The schema name must not begin with "pg_" (PostgreSQL reserved prefix).'
      );
    }
    elseif ('public' == $schema_name) {
      $issue = t(
        'The "public" schema is reseved to Drupal and should not be used for Chado.'
      );
    }
    elseif ((self::TEST_SCHEMA_NAME == $schema_name) && !ChadoSchema::$test_mode) {
      // @todo: Should we protect the "_" prefix and not just "_chado_test"?
      // Value of \Drupal\Tests\tripal_chado::$schemaName.
      $issue = t(
        'The "' . self::TEST_SCHEMA_NAME . '" schema name is reseved for Tripal internal uses and unit tests.'
      );
    }
    elseif (63 < strlen($schema_name)) {
      $issue = t(
        'The schema name must contain less than 64 characters.'
      );
    }

    return $issue;
  }

  /**
   * Returns a PostgreSQL quoted object name.
   *
   * Use PostgreSQL to quote an object identifier if needed for SQL queries.
   * For instance, a schema or a table name using special characters may need to
   * be quoted if used in SQL queries.
   *
   * For instance, with a schema called "schema" and a table "co$t", a query
   * should look like:
   * @code
   *   SELECT * FROM schema."co$t";
   * @endcode
   * while with a schema called "schéma" and a table "cost", a query should look
   * like:
   * @code
   *   SELECT * FROM "schéma".cost;
   * @endcode
   * Inappropriate object quoting would lead to SQL errors.
   * This function has to be called for each object separately (one time for the
   * schema and one time for the table in above examples) and it only adds quote
   * when necessary.
   */
  public static function quotePgObjectId($object_id) {
    $sql = "SELECT quote_ident(:object_id) AS \"qi\";";
    $quoted_object_id = \Drupal::database()
      ->query($sql, [':object_id' => $object_id])
      ->fetch()
      ->qi ?: $object_id
    ;
    return $quoted_object_id;
  }

  /**
   * Retrieve schema details from YAML file.
   *
   * @param string $version
   *   Version of Chado schema to fetch.
   *
   * @return
   *   An array with details for the current schema version.
   */
  public static function getChadoSchemaStructure($version = '1.3') {
    static $schema_structure = [];
    if (empty($schema_structure[$version])) {
      $filename =
        drupal_get_path('module', 'tripal_chado')
        . '/chado_schema/chado_schema-'
        . $version
        . '.yml'
      ;
      if (file_exists($filename)) {
        $schema_structure[$version] = Yaml::parse(file_get_contents($filename));
      }
      else {
        throw new \Exception("Invalid or unsupported Chado schema version '$version'.");
      }
    }
    return $schema_structure[$version];
  }

  /**
   * Returns the version number of the given Chado schema.
   *
   * For recent Chado instances, version is stored in the schema while version
   * number has to be guessed in older versions (using specific table presence).
   *
   * @param string $schema_name
   *   A schema name.
   * @param string $db_name
   *   The name of the database to use.
   *
   * @return string
   *   The version of Chado ('1.0', '1.1x', '1.2x' '1.3x', '1.4+') or 0 if the
   *   version cannot be guessed but a Chado instance has been detected or FALSE
   *   if the schema is not a chado schema. The returned version always starts
   *   by a number and can be tested against numeric values (ie. ">= 1.2").
   */
  public static function getChadoVersion($schema_name, $db_name = '') {
    // By default, we ignore the version.
    $version = FALSE;
    
    // Get database connection.
    $db = ChadoSchema::getDatabase($db_name);
    
    $install_select = $db->select('chado_installations' ,'i')
      ->fields('i', ['version'])
      ->condition('schema_name', $schema_name, '=')
      ->execute();
    $result = $install_select->fetch();
    
    if ($result) {
      $version = $result->version;
    }
    else {
      // Not integrated into Tripal, make sure it is a Chado schema.
      // An arbitrary list of typical Chado tables.
      $chado_tables = [
        'db',
        'dbxref',
        'cv',
        'cvterm',
        'project',
        'organism',
        'synonym',
        'feature',
        'stock',
        'analysis',
        'study',
        'contact',
        'pub',
        'phylonode',
        'phylotree',
        'library',
      ];
      // Check if the schema contains typical Chado tables by counting them.
      $sql_query = "
        SELECT COUNT(1) AS \"cnt\"
        FROM pg_tables
        WHERE schemaname=:schema AND tablename IN (:tables[]);
      ";
      $table_match_count = $db
        ->query(
          $sql_query,
          [':schema' => $schema_name, ':tables[]' => $chado_tables]
        )
        ->fetchField()
      ;
      // Do we have a match?
      if (count($chado_tables) == $table_match_count) {
        // We got a Chado, try to get it from chadoprop table.
        $version = 0;
        $sql_query = "
          SELECT true
          FROM pg_tables
          WHERE
            schemaname = :schema
            AND tablename = 'chadoprop'
        ;";
        $prop_exists = $db
          ->query(
            $sql_query,
            [':schema' => $schema_name]
          )
          ->fetchField()
        ;

        if ($prop_exists) {
          // Get it from chadoprop table.
          // First get a quoted name for query.
          $quoted_schema_name = ChadoSchema::quotePgObjectId($schema_name);
          $sql_query = "
            SELECT value
            FROM $quoted_schema_name.chadoprop cp
              JOIN $quoted_schema_name.cvterm cvt ON cvt.cvterm_id = cp.type_id
              JOIN $quoted_schema_name.cv CV ON cvt.cv_id = cv.cv_id
            WHERE
              cv.name = 'chado_properties'
              AND cvt.name = 'version'
            ;
          ";
          $v = $db->query($sql_query)->fetchObject();

          // If we don't have a version in the chadoprop table then it must be
          // v1.11 or older.
          if ($v) {
            $version = $v->value;
          }
        }

        // Try to guess it from schema content from table specific to newer
        // versions (https://github.com/GMOD/Chado/tree/master/chado/schemas).
        if (!$version) {
          // 'feature_organism' table added in 0.02.
          if (ChadoSchema::checkSchemaTableExists(
                'feature_organism',
                $schema_name,
                $db_name
              )
          ) {
            $version = '0.02';
          }

          // 'cv.cvname' column replaced by 'cv.name' after 0.03.
          if (ChadoSchema::checkSchemaColumnExists(
                'cv',
                'cvname',
                $schema_name,
                $db_name
              )
          ) {
            $version = '0.03';
          }
          
          // 'feature_cvterm_dbxref' table added in 1.0.
          if (ChadoSchema::checkSchemaTableExists(
                'feature_cvterm_dbxref',
                $schema_name,
                $db_name
              )
          ) {
            $version = '1.0';
          }

          // 'cell_line' table added in 1.1-1.11.
          if (ChadoSchema::checkSchemaTableExists(
                'cell_line',
                $schema_name,
                $db_name
              )
          ) {
            $version = '1.1x';
          }

          // 'cvprop' table added in 1.2-1.24.
          if (ChadoSchema::checkSchemaTableExists(
                'cvprop',
                $schema_name,
                $db_name
              )
          ) {
            $version = '1.2x';
          }

          // 'analysis_cvterm' table added in 1.3-1.31.
          if (ChadoSchema::checkSchemaTableExists(
                'analysis_cvterm',
                $schema_name,
                $db_name
              )
          ) {
            $version = '1.3x';
          }

          // 'featureprop.cvalue_id' column added in 1.4.
          if (ChadoSchema::checkSchemaColumnExists(
                'featureprop',
                'cvalue_id',
                $schema_name,
                $db_name
              )
          ) {
            $version = '1.4+';
          }
        }
      }
    }

    return $version;
  }

  /**
   * Retrieves the list of tables in the given schema.
   *
   * Note: only peristant tables (ie. no unlogged or temporary tables) visible
   * by current DB user are returned.
   *
   * @param string $schema_name
   *   A schema name.
   * @param array $include
   *   an associative array to select other element type to include with the
   *   tables. Supported keys are:
   *   'v': include views;
   *   'p': include partitions;
   *   'm': include PostgreSQL materialized views (not to be confused with
   *        Tripal materialized views which currently are regular tables);
   *   Values are currently not used and could be just set to TRUE.
   *   Default: tables only (empty array).
   * @param string $db_name
   *   The name of the database to use.
   *
   * @returns
   *   An associative array where the keys are the table names and values are
   *   array of object porperties such as:
   *   'type': one of 'r' for table, 'v' for view, 'p' for partitions, 'm' for
   *   PostgreSQL materialized views.
   */
  public static function getSchemaTables(
    $schema_name,
    $include = [],
    $db_name = ''
  ) {
    // Get database connection.
    $db = ChadoSchema::getDatabase($db_name);

    // No "{}" around table names as we query system tables.
    $sql_query = "
      SELECT
        DISTINCT c.relname,
        c.relkind
      FROM pg_class c
        JOIN pg_namespace n ON (n.oid = c.relnamespace)
      WHERE
        n.nspname = :schema_name
        AND c.relkind IN (:object_types[])
        AND c.relpersistence = 'p'
      ORDER BY c.relkind, c.relname;
    ";
    // We always want tables.
    $include['r'] = TRUE;
    // Allowed values.
    $allowed_types = ['r' => 1, 'p' => 1, 'm' => 1, 'v' => 1, ];
    $object_types = array_keys(array_intersect_key($include, $allowed_types));
    $results = $db
      ->query(
        $sql_query,
        [
          ':schema_name' => $schema_name,
          ':object_types[]' => $object_types,
        ]
      )
    ;
    $tables = [];
    foreach ($results as $table) {
      $tables[$table->relname] = ['type' => $table->relkind];
    }

    return $tables;
  }

  /**
   * Retrieves the chado table DDL (table data definition language).
   *
   * @param string $table
   *   The name of the table to retrieve.
   * @param string $schema_name
   *   The name of the table schema.
   * @param string $db_name
   *   The name of the database to use.
   *
   * @returns string
   *   A set of SQL queries used to create the table including its constraints
   *   or an empty string if the table was not found.
   */
  public static function getSchemaTableDdl(
    $table_name,
    $schema_name=  'chado',
    $db_name = ''
  ) {
    // Get database connection.
    $db = ChadoSchema::getDatabase($db_name);

    $sql_query = "SELECT public.tripal_get_table_ddl(:schema, :table, TRUE) AS \"definition\";";
    $result = $db->query(
        $sql_query,
        [':schema' => $schema_name, ':table' => $table_name, ]
    );
    $table_raw_definition = '';
    if ($result) {
      $table_raw_definition = $result->fetch()->definition;
    }
    return $table_raw_definition;
  }

  /**
   * Turns a table DDL string into a more usable structure.
   *
   * @param string $table_ddl
   *   A string containing table definition as returned by
   *   ChadoSchema::getTableDdl().
   *
   * @returns array
   *   An associative array with the following structure:
   *   [
   *     'columns' => [
   *       <column name> => [
   *        'type'    => <column type>,
   *        'null'    => <TRUE if column can be NULL, FALSE otherwise>,
   *        'default' => <'DEFAULT ' followed by column default value>,
   *       ],
   *       ...
   *     ],
   *     'constraints' => [
   *       <constraint name> => <constraint definition>,
   *       ...
   *     ],
   *     'indexes' => [
   *       <index name> => [
   *         'query' => <index creation query>,
   *         'name'  => <index name>,
   *         'table' => <'table.column' names owning the index>,
   *         'using' => <index type/structure>,
   *       ],
   *       ...
   *     ],
   *   ]
   */
  public static function parseSchemaTableDdl($table_ddl) {
    $table_definition = [
      'columns' => [],
      'constraints' => [],
      'indexes' => [],
    ];
    // Note: if we want to process more exotic table creation strings not
    // comming from ChadoSchema::getTableDdl(), we will have to reformat the
    // string first here.
    $table_raw_definition = explode("\n", $table_ddl);

    // Skip "CREATE TABLE" line.
    $i = 1;
    // Loop until end of table definition.
    while (($i < count($table_raw_definition))
        && (!preg_match('/^\s*\)\s*;\s*$/', $table_raw_definition[$i]))
    ) {
      if (empty($table_raw_definition[$i])) {
        ++$i;
        continue;
      }
      if (
          preg_match(
            '/^\s*CONSTRAINT\s*([\w\$\x80-\xFF\.]+)\s+(.+?),?\s*$/',
            $table_raw_definition[$i],
            $match
          )
      ) {
        // Constraint.
        $table_definition['constraints'][$match[1]] = $match[2];
      }
      elseif (
        preg_match(
          '/^\s*(\w+)\s+(\w+.*?)(\s+NOT\s+NULL|\s+NULL|)(\s+DEFAULT\s+.+?|),?\s*$/',
          $table_raw_definition[$i],
          $match
        )
      ) {
        // Column.
        $table_definition['columns'][$match[1]] = [
          'type'    => $match[2],
          'null'    => (FALSE === stripos($match[3], 'NOT')),
          'default' => $match[4],
        ];
      }
      else {
        // If it happens, it means the tripal_get_table_ddl() SQL function
        // changed and this script should be adapted.
        throw new \Exception(
          'Failed to parse unexpected table definition line format for "'
          . $table_raw_definition[0]
          . '": "'
          . $table_raw_definition[$i]
          . '"'
        );
      }
      ++$i;
    }

    // Parses indexes.
    if (++$i < count($table_raw_definition)) {
      while ($i < count($table_raw_definition)) {
        if (empty($table_raw_definition[$i])) {
          ++$i;
          continue;
        }
        // Parse index name for later comparison.
        if (preg_match(
              '/
                ^\s*
                CREATE\s+
                (?:UNIQUE\s+)?INDEX\s+(?:CONCURRENTLY\s+)?
                (?:IF\s+NOT\s+EXISTS\s+)?
                # Capture index name.
                ([\w\$\x80-\xFF\.]+)\s+
                # Capture table column.
                ON\s+([\w\$\x80-\xFF\."]+)\s+
                # Capture index structure.
                USING\s+(.+);\s*
                $
              /ix',
              $table_raw_definition[$i],
              $match
            )
        ) {
          // Constraint.
          $table_definition['indexes'][$match[1]] = [
            'query' => $match[0],
            'name'  => $match[1],
            'table'  => $match[2],
            'using' => $match[3],
          ];
        }
        else {
          // If it happens, it means the tripal_get_table_ddl() SQL function
          // changed and this script should be adapted.
          throw new \Exception(
            'Failed to parse unexpected table DDL line format for "'
            . $table_raw_definition[0]
            . '": "'
            . $table_raw_definition[$i]
            . '"'
          );
        }
        ++$i;
      }
    }
    return $table_definition;
  }
  
  //@TODO: add conversion from DDL to Drupal Schema API.

  /**
   * Check that any given chado schema exists.
   *
   * @param string $schema
   *   The name of the schema to check the existence of.
   * @param string $db_name
   *   The name of the database to use.
   *
   * @return bool
   *   TRUE/FALSE depending upon whether or not the schema exists.
   */
  public static function checkSchemaExists($schema_name, $db_name = '') {

    // Get database connection.
    $db = ChadoSchema::getDatabase($db_name);

    // First make sure we have a valid schema name.
    $schema_issue = ChadoSchema::isInvalidSchemaName($schema_name);
    if ($schema_issue) {
      return FALSE;
    }

    $sql_query = "
      SELECT TRUE
      FROM pg_namespace
      WHERE
        has_schema_privilege(nspname, 'USAGE')
        AND nspname = :nspname
      ;
    ";
    $schema_exists = $db
      ->query($sql_query, [':nspname' => $schema_name])
      ->fetchField()
    ;
    return ($schema_exists ? TRUE : FALSE);
  }
  
  /**
   * Check that any given Chado table or view exists.
   *
   * This function is necessary because Drupal's db_table_exists() function will
   * not look in any other schema but the one where Drupal is installed
   *
   * @param string $table_name
   *   The name of the table whose existence should be checked. Note that table
   *   names are case sensitive if quoted.
   * @param string $schema_name
   *   Name of the schema in which the table should be existing.
   * @param string $db_name
   *   Name of the database containing the given schema.
   *
   * @return mixed
   *   FALSE if the table does not exist or the table type ('BASE TABLE' or
   *   'VIEW') if the table exists.
   */
  public static function checkSchemaTableExists(
    $table_name,
    $schema_name = 'chado',
    $db_name = ''
  ) {
    static $db_tables = [];

    // Get database connection.
    $db = ChadoSchema::getDatabase($db_name);

    // If we've already lookup up this table then don't do it again, as
    // we don't need to keep querying the database for the same thing.
    if (!isset($db_tables["$db_name.$schema_name.$table_name"])) {
      $sql_query = "
        SELECT table_type
        FROM information_schema.tables
        WHERE
          table_name = :table_name
          AND table_schema = :schema_name
          AND table_catalog = :db_name
        ;
      ";
      $args = [
        ':table_name' => $table_name,
        ':schema_name' => $schema_name,
        ':db_name' => $db_name,
      ];
      $result = $db->query($sql_query, $args)->fetch();
      if (empty($result)) {
        $db_tables["$db_name.$schema_name.$table_name"] = FALSE;
      }
      else {
        $db_tables["$db_name.$schema_name.$table_name"] = $result->table_type;
      }
    }

    return $db_tables["$db_name.$schema_name.$table_name"];
  }

  /**
   * Check that any given column in a Chado table exists.
   *
   * This function is necessary because Drupal's db_field_exists() will not
   * look in any other schema but the one were Drupal is installed
   *
   * @param string $table_name
   *   The name of the chado table. Note that table names are case
   *   sensitive if quoted.
   * @param string $column_name
   *   The name of the column in the table. Note that column names are case
   *   sensitive if quoted.
   *
   * @return mixed
   *   FALSE if the column does not exist or the column data type if the column
   *   exists ('bigint', 'text', etc.).
   *
   * @ingroup tripal_chado_schema_api
   */
  public static function checkSchemaTableColumnExists(
    $table_name,
    $column_name,
    $schema_name = 'chado',
    $db_name = ''
  ) {
    static $db_columns = [];

    // Get database connection.
    $db = ChadoSchema::getDatabase($db_name);
    
    // If we've already lookup up this table then don't do it again, as
    // we don't need to keep querying the database for the same thing.
    if (!isset($db_columns["$db_name.$schema_name.$table_name.$column_name"])) {
      $sql_query = "
        SELECT data_type
        FROM information_schema.columns
        WHERE
          table_name = :table_name AND
          column_name = :column_name AND
          table_schema = :schema_name AND
          table_catalog = :db_name
        ;
      ";
      $args = [
        ':table_name' => $table_name,
        ':column_name' => $column_name,
        ':schema_name' => $schema_name,
        ':db_name' => $db_name,
      ];
      $result = $db->query($sql_query, $args)->fetch();
      if (empty($result)) {
        $db_columns["$db_name.$schema_name.$table_name.$column_name"] = FALSE;
      }
      else {
        $db_columns["$db_name.$schema_name.$table_name.$column_name"] =
          $result->data_type;
      }
    }

    return $db_columns["$db_name.$schema_name.$table_name.$column_name"];
  }

  /**
   * Check that any given sequence in a Chado table exists.
   *
   * When the sequence name is not known, a table name and a column name can be
   * specified instead. Then, the sequence name will be guessed from those and
   * returned into the given $sequence_name variable if provided. If both the
   * sequence name and the table and column names are specified, only the
   * sequence name will be taken into account.
   *
   * @param string $table_name
   *   The name of the table the sequence is used in.
   * @param string $column_name
   *   The name of the column the sequence is used to populate.
   * @param string &$sequence_name
   *   The name of the sequence is to check if known. Otherwise, the function
   *   will set it.
   * @param string $schema_name
   *   The name of the schema containing the sequence.
   * @param string $db_name
   *   The name of the database to use.
   *
   * @return boolean
   *   TRUE if the seqeuence exists in the chado schema and FALSE if it does
   *   not.
   *
   * @ingroup tripal_chado_schema_api
   */
  public static function checkSchemaSequenceExists(
    $table_name = NULL,
    $column_name = NULL,
    &$sequence_name = NULL,
    $schema_name = 'chado',
    $db_name = ''
  ) {
    static $table_column_sequence_lookup = [];
    static $db_sequences = [];

    // Get database connection.
    $db = ChadoSchema::getDatabase($db_name);

    // If no sequence name is provided, guess it.
    if ($sequence_name === NULL) {
      // Make sure we have a table and a column.
      if (empty($table_name) || empty($column_name)) {
        throw new \Exception('Invalid parameters for checkSchemaSequenceExists(). You must specify either at least a table and a column name, or a sequence name.');
      }

      // If we've already lookup up this table then don't do it again, as
      // we don't need to keep querying the database for the same thing.
      if (!isset($db_sequences["$db_name.$schema_name.$table_name.$column_name"])) {
        $prefixed_table =
          (!empty($schema_name) ? "$schema_name." : '')
          . $table_name
        ;
        $sequence_name = $db
          ->query(
            'SELECT pg_get_serial_sequence(:schema_table, :column);',
            [':schema_table' => $prefixed_table, ':column' => $column_name]
          )
          ->fetchField()
        ;

        // Remove prefixed table from sequence name
        $db_sequences["$db_name.$schema_name.$table_name.$column_name"] =
          $sequence_name =
          str_replace("$schema_name.", '', $sequence_name)
        ;
      }
      else {
        $sequence_name =
          $db_sequences["$db_name.$schema_name.$table_name.$column_name"];
      }
    }

    if (!isset($db_sequences["$db_name.$schema_name.$sequence_name"])) {
      $sql_query = "
        SELECT TRUE
        FROM information_schema.sequences
        WHERE
          sequence_name = :sequence_name
          AND sequence_schema = :schema
          AND sequence_catalog = :catalog
      ";
      $args = [
        ':sequence_name' => $sequence_name,
        ':schema' => $schema_name,
        ':catalog' => $db_name,
      ];
      $result = $db->query($sql_query, $args)->fetch();
      $db_sequences["$db_name.$schema_name.$sequence_name"] = !empty($result);
    }

    return $db_sequences["$db_name.$schema_name.$sequence_name"];
  }

  /**
   * Checks if an index exists on a given table.
   *
   * The index can be specified either from a list of column that are indexed
   * or by its name. If both parameters ($columns and $index_name) are provided,
   * the function will check if the given index exactly indexes the given
   * columns and if not, it will return FALSE with the correct column names in
   * $columns if the index exists or an empty array if not.
   *
   * @param string $table_name
   *   The table that owns the index.
   * @param array &$columns
   *   A list of columns indexed by the index. Can be left empty if an index
   *   name is provided. If an empty array is provided with $index_name, the
   *   array of columns will be updated according to the index if found.
   * @param string $index_name
   *   The name of the index. Can be left empty if a list of column is provided.
   * @param string $schema_name
   *   Name of the schema in which the index should be existing.
   * @param string $db_name
   *   Name of the database containing the given schema.
   * @param $return_array
   *   If TRUE, returns matching index names in an array instead of a single
   *   name in a string. It is only useful when there are duplicate indexes that
   *   matches a set of columns (ie. no index name provided) and would need to
   *   be cleaned/removed. Default: FALSE.
   *
   * @return mixed
   *   FALSE if no matching index was found, otherwise a string containing the
   *   name of the first matching index found (in alpha order). If $return_array
   *   is TRUE, an array is returned and will contain no, one or more matching
   *   index names.
   */
  public static function checkSchemaIndexExists(
    $table_name,
    &$columns = NULL,
    $index_name = NULL,
    $schema_name = 'chado',
    $db_name = '',
    $return_array = FALSE
  ) {
    static $db_indexes = [];
    static $from_columns = [];
    $index_return = FALSE;

    if (empty($columns) && empty($index_name)) {
      throw new \Exception('Invalid parameters for checkSchemaIndexExists(). You must specify either at least a list of column names or an index name.');
    }

    // Get database connection.
    $db = ChadoSchema::getDatabase($db_name);

    if (!empty($index_name)) {
      // We got an index name to check.
      // Cached data differs for "index" keys as we need to store column names.
      // Therefore we use 2 cache keys, one to store corresponding column names
      // with the corresponding cache key to use to get the index list and
      // another one that will remain either unchanged and contain FALSE if
      // the index does not exist (and has no associated columns) or updated to
      // the cache key format that contains columns. So we would reach the same
      // cache using the index or its columns.
      // This is the regular cache key.
      $cache_key = "$db_name.$schema_name.$table_name/$index_name";
      // The following cache key will be used to see if we already processed the
      // given index.
      $index_cache_key = "$cache_key/data";
      if (!isset($db_indexes[$index_cache_key])) {
        $db_indexes[$index_cache_key] = [
          'columns' => [],
          'cache_key' => $cache_key,
        ];
        $sql_query = "
          SELECT c.relname AS \"index\", array_agg(a.attname) AS \"columns\"
          FROM pg_index i
            JOIN pg_class c ON c.oid = i.indexrelid
            JOIN pg_class t ON t.oid = i.indrelid
            JOIN pg_namespace n ON (n.oid = c.relnamespace AND n.nspname = :schema),
            pg_attribute a
          WHERE
            t.relname = :table
            AND c.relname = :index
            AND a.attrelid = t.oid
            AND a.attnum = ANY(i.indkey)
          GROUP BY c.relname, t.relname;
        ";
        $args = [
          ':table' => $table_name,
          ':index' => $index_name,
          ':schema' => $schema_name,
        ];
        $index = $db->query($sql_query, $args)->fetch();
        // We have at most one result.
        if (empty($index)) {
          // No result, use default "index" key type.
          $db_indexes[$cache_key] = FALSE;
          // No associate columns.
          $db_columns = [];
        }
        else {
          // Get columns and update parameter.
          $db_columns = explode(',', substr($index->columns, 1, -1));
          sort($db_columns);
          // And update key to use "column list" key type.
          $cache_key =
            "$db_name.$schema_name.$table_name."
            . implode('|', $db_columns)
          ;
          // Fill cache.
          $db_indexes[$index_cache_key] = [
            'columns' => $db_columns,
            'cache_key' => $cache_key,
          ];
          $db_indexes[$cache_key] = [$index->index];
        }
      }
      $db_columns = $db_indexes[$index_cache_key]['columns'];
      $cache_key = $db_indexes[$index_cache_key]['cache_key'];
    }
    else {
      // Get by indexed column names.
      // We have columns, make sure we got a (non empty) array.
      if (!is_array($columns)) {
        throw new \Exception('Invalid parameter for checkSchemaIndexExists(). $columns parameter must be an array of one or more column names.');
      }
      $db_columns = $columns;
      sort($db_columns);
      $cache_key =
        "$db_name.$schema_name.$table_name."
        . implode('|', $db_columns)
      ;
      // Don't use the cache if we want all the indexes and it was not filled
      // from a list of columns (we could miss an index).
      if (!isset($db_indexes[$cache_key])
          || (empty($from_columns[$cache_key]) && $return_array)
      ) {
        $sql_query = "
          SELECT c.relname AS \"index\", array_agg(a.attname) AS \"columns\"
          FROM pg_index i
            JOIN pg_class c ON c.oid = i.indexrelid
            JOIN pg_class t ON t.oid = i.indrelid
            JOIN pg_namespace n ON (n.oid = c.relnamespace AND n.nspname = :schema),
            pg_attribute a
          WHERE
            t.relname = :table
            AND a.attrelid = t.oid
            AND a.attnum = ANY(i.indkey)
            AND a.attname IN (:columns[])
            AND i.indnatts = :column_count
          GROUP BY c.relname, t.relname;
        ";
        $args = [
          ':table' => $table_name,
          ':columns[]' => $db_columns,
          ':column_count' => count($db_columns),
          ':schema' => $schema_name,
        ];
        $indexes = $db->query($sql_query, $args)->fetchAll();
        $db_indexes[$cache_key] = [];
        foreach ($indexes as $index) {
          $db_indexes[$cache_key][] = $index->index;
        }
        $from_columns[$cache_key] = TRUE;
      }
    }

    // Check if we should also check that columns match.
    if (!empty($index_name) && !empty($columns)) {
      sort($columns);
      $column_str = strtolower(implode('|', $columns));
      $db_column_str = strtolower(implode('|', $db_columns));
      if ($db_column_str != $column_str) {
        // Not matching. Update use columns to let know.
        $columns = $db_columns;
        // Stop here even if the index exists.
        return FALSE;
      }
    }

    // Update user columns parameter.
    $columns = $db_columns;

    // Check return type.
    if ($return_array) {
      $index_return = $db_indexes[$cache_key];
    }
    elseif (!empty($db_indexes[$cache_key])) { 
      $index_return = $db_indexes[$cache_key][0];
    }

    return $index_return;
  }

  /**
   * Check that the given function exists.
   *
   * @param string $function_name
   *   The name of the function.
   * @param string $func_parameters
   *   An ordered array of input parameter types that are part of the function
   *   signature.
   * @param string $schema_name
   *   The name of the schema containing the function.
   * @param string $db_name
   *   The name of the database to use.
   *
   * @return boolean
   *   TRUE if the function exists in the schema and FALSE otherwise.
   *
   * @ingroup tripal_chado_schema_api
   */
  public static function checkSchemaFunctionExists(
    $function_name,
    $function_parameters,
    $schema_name = 'chado',
    $db_name = ''
  ) {
    static $db_functions = [];

    // Get database connection.
    $db = ChadoSchema::getDatabase($db_name);

    if (empty($function_name) || !is_array($function_parameters)) {
      throw new \Exception('Invalid parameters for checkSchemaFunctionExists().');
    }
    // Reformat function signature.
    $function_signature = preg_replace(
      '/(?:^\s+(\S))|\s+(,)|( )\s+|(?:(\S)\s+$)/',
      '\1\2\3\4',
      strtolower(implode(', ', $function_parameters))
    );
    // Remap invalid types (for people not using the appropriate names).
    $function_signature = preg_replace(
        [
          '/varchar/',
          '/(^|\W)int4?(\W|$)/',
          '/(^|\W)int8(\W|$)/',
          '/(^|\W)bool(\W|$)/',
          '/(^|\W)string(\W|$)/',
        ],
        [
          'character varying',
          '\1integer\2',
          '\1bigint\2',
          '\1boolean\2',
          '\1text\2',
        ],
        $function_signature
    );

    
    $cache_key =
      "$db_name.$schema_name.$function_name("
      . $function_signature
      . ")"
    ;
    if (!isset($db_functions[$cache_key])) {
      $sql_query = "
        SELECT TRUE
        FROM
          pg_proc p
          JOIN pg_namespace n ON (
            n.oid = p.pronamespace
            AND n.nspname = :schema
          )
        WHERE
          p.proname = :function_name
          AND pg_get_function_identity_arguments(p.oid) = :func_args
      ";
      $args = [
        ':function_name' => $function_name,
        ':func_args' => $function_signature,
        ':schema' => $schema_name,
      ];
      $result = $db->query($sql_query, $args)->fetch();

      $db_functions[$cache_key] = !empty($result);
    }

    return $db_functions[$cache_key];
  }

  /**
   * Return table dependencies.
   *
   * @TODO: migrate to Chado API
   *
   * @param $chado_schema
   *   Name of the schema to process.
   *
   * @return array
   *   first-level keys are table name, second level keys are column names,
   *   third level keys are foreign table names and values are foreign column
   *   names.
   */
  public static function getSchemaTableDependencies(
    $schema_name = 'chado',
    $db_name = ''
  ) {
    $db = ChadoSchema::getDatabase($db_name);

    // Get tables.
    $sql_query = "
      SELECT
        DISTINCT c.relname, c.relkind
      FROM
        pg_class c
        JOIN pg_namespace n ON (
          n.oid = c.relnamespace
          AND n.nspname = :schema
        )
      WHERE
        c.relkind IN ('r','p')
        AND c.relpersistence = 'p'
      ORDER BY c.relkind DESC, c.relname
    ";
    $tables = $db
      ->query($sql_query, [':schema' => $schema_name])
      ->fetchAllAssoc('relname')
    ;

    $table_dependencies = [];
    // Process all tables.
    foreach ($tables as $table_name => $table) {
      $table_dependencies[$table_name] = [];

      // Get new table definition.
      $table_definition = ChadoSchema::parseSchemaTableDdl(
        ChadoSchema::getSchemaTableDdl($table_name, $schema_name, $db_name)
      );

      // Process FK constraints.
      $foreign_keys = [];
      $cstr_defs = $table_definition['constraints'];
      foreach ($cstr_defs as $constraint_name => $constraint_def) {
        if (preg_match(
              '/
                # Match "FOREIGN KEY ("
                FOREIGN\s+KEY\s*\(
                   # Capture current table columns (one or more).
                  (
                    (?:[\w\$\x80-\xFF\.]+\s*,?\s*)+
                  )
                \)\s*
                # Match "REFERENCES"
                REFERENCES\s*
                  # Caputre evental schema name.
                  ([\w\$\x80-\xFF]+\.|)
                  # Caputre foreign table name.
                  ([\w\$\x80-\xFF]+)\s*
                  \(
                    # Capture foreign table columns (one or more).
                    (
                      (?:[\w\$\x80-\xFF]+\s*,?\s*)+
                    )
                  \)
              /ix',
              $constraint_def,
              $match
            )
        ) {
          $table_columns =  preg_split('/\s*,\s*/', $match[1]);
          $foreign_table_schema = $match[2];
          $foreign_table = $match[3];
          $foreign_table_columns =  preg_split('/\s*,\s*/', $match[4]);
          if (count($table_columns) != count($foreign_table_columns)) {
            throw new \Exception("Failed to parse foreign key definition for table '$table_name':\n'$constraint_def'");
          }
          else {
            for ($i = 0; $i < count($table_columns); ++$i) {
              $table_dependencies[$table_name][$table_columns[$i]] = [
                $foreign_table => $foreign_table_columns[$i],
              ];
            }
          }
        }
      }
    }
    return $table_dependencies;
  }
//++++++++++++++++++++++++++++++++++++++++++++++++++++++++++++++++++++++++++++++
  /**
   * The ChadoSchema constructor.
   *
   * @param string $version
   *   The current version for this site. E.g. "1.3". If a version is not
   *   provided, the version of the current database will be looked up.
   */
  public function __construct($version = NULL, $schema_name = NULL) {

    // Setup a logger.
    $this->logger = \Drupal::logger('tripal_chado');

    // Cache the connection to the database.
    $this->connection = Database::getConnection();
    $databases = $this->connection->getConnectionOptions();
    $this->default_db = $databases['database'];
    
    // Open a direct PHP connection to the database to bypass Drupal security
    // restrictions (ie. a single statement per query). This connection is only
    // used in a restricted number of specific situations and queries passed to
    // it should never be build from user inputs for security reasons.
    $dsn = sprintf(
      'dbname=%s host=%s port=%s user=%s password=%s',
      $databases['database'],
      $databases['host'],
      $databases['port'],
      $databases['username'],
      $databases['password']
    );
    $pgconnection = pg_connect($dsn);
    if (!$pgconnection) {
      $this->logger->error(
        "Unable to connect to database using '$dsn' connection string.\n"
      );
    }
    $this->pgconnection = $pgconnection;

    // Set the version of the schema.
    if ($version === NULL) {
      $this->version = chado_get_version(TRUE, $schema_name);
    }
    else {
      $this->version = $version;
    }

    // Set the name of the schema.
    if ($schema_name === NULL) {
      $this->schema_name = 'chado';
    }
    elseif ($schema_issue = ChadoSchema::isInvalidSchemaName($schema_name)) {
      $this->logger->error($schema_issue);
      return FALSE;
    }
    else {
      $this->schema_name = $schema_name;
    }

    // Check functions require the chado schema be local and installed...
    // So lets check that now...
    if (ChadoSchema::checkSchemaExists($schema_name) !== TRUE) {
      $this->logger->error(
        'Schema must already exist and be in the same database as your
        Drupal installation.');
      return FALSE;
    }
  }

  /**
   * Returns the version number of the Chado this object references.
   *
   * @returns
   *   The version of Chado
   */
  public function getVersion() {
    return $this->version;
  }

  /**
   * Retrieve the name of the PostgreSQL schema housing Chado.
   *
   * @return
   *   The name of the schema.
   */
  public function getSchemaName() {
    return $this->schema_name;
  }

  /**
   * Retrieves the list of tables in the Chado schema.  By default it only
   * returns the default Chado tables, but can return custom tables added to
   * the Chado schema if requested.
   *
   * @param $include_custom
   *   Optional.  Set as TRUE to include any custom tables created in the
   *   Chado schema. Custom tables are added to Chado using the
   *   tripal_chado_chado_create_table() function.
   *
   * @returns
   *   An associative array where the key and value pairs are the Chado table
   *   names.
   */
  public function getTableNames($include_custom = FALSE) {

    $schema = $this->getSchemaDetails();
    $tables = array_keys($schema);

    // now add in the custom tables too if requested
    // @todo change this to the variable once custom tables are supported.
    if (FALSE) {
      $sql = "SELECT table FROM {tripal_custom_tables}";
      $resource = $this->connection->query($sql);

      foreach ($resource as $r) {
        $tables[$r->table] = $r->table;
      }
    }

    asort($tables);
    return $tables;

  }

  /**
   * Retrieves the chado tables Schema API array.
   *
   * @param $table
   *   The name of the table to retrieve.  The function will use the appopriate
   *   Tripal chado schema API hooks (e.g. v1.11 or v1.2).
   *
   * @returns
   *   A Drupal Schema API array defining the table.
   */
  public function getTableSchema($table) {

    $schema = $this->getSchemaDetails();

    if (isset($schema[$table])) {
      $table_arr = $schema[$table];
    }
    else {
      $table_arr =  FALSE;
    }

    // Ensure all the parts are set.
    if (!isset($table_arr['primary key'])) { $table_arr['primary key'] = []; }
    if (!isset($table_arr['unique keys'])) { $table_arr['unique keys'] = []; }
    if (!isset($table_arr['foreign keys'])) { $table_arr['foreign keys'] = []; }
    if (!isset($table_arr['referring_tables'])) { $table_arr['referring_tables'] = []; }


    // Ensures consistency regardless of the number of columns of the pkey.
    $table_arr['primary key'] = (array) $table_arr['primary key'];

    // Ensure this is parsed as an array.
    if (is_string($table_arr['referring_tables'])) {
      $table_arr['referring_tables'] = explode(', ', $table_arr['referring_tables']);
    }

    // Ensure the unique keys are arrays.
    foreach ($table_arr['unique keys'] as $ukname => $ukcolumns) {
      if (is_string($ukcolumns)) {
        $table_arr['unique keys'][$ukname] = explode(', ', $ukcolumns);
      }
    }

    // Ensure foreign key array is present for consistency.
    if (!isset($table_arr['foreign keys'])) {
      $table_arr['foreign keys'] = [];
    }

    // if the table_arr is empty then maybe this is a custom table
    if (!is_array($table_arr) or count($table_arr) == 0) {
      //$table_arr = $this->getCustomTableSchema($table);
      return FALSE;
    }

    return $table_arr;

  }

  /**
   * Retrieves the schema array for the specified custom table.
   *
   * @param $table
   *   The name of the table to create.
   *
   * @return
   *   A Drupal-style Schema API array definition of the table. Returns
   *   FALSE on failure.
   */
  public function getCustomTableSchema($table) {

    $sql = "SELECT schema FROM {tripal_custom_tables} WHERE table_name = :table_name";
    $results = $this->connection->query($sql, [':table_name' => $table]);
    $custom = $results->fetchObject();
    if (!$custom) {
      return FALSE;
    }
    else {
      return unserialize($custom->schema);
    }
  }

  /**
   *  Returns all chado base tables.
   *
   *  Base tables are those that contain the primary record for a data type.
   * For
   *  example, feature, organism, stock, are all base tables.  Other tables
   *  include linker tables (which link two or more base tables), property
   * tables, and relationship tables.  These provide additional information
   * about primary data records and are therefore not base tables.  This
   * function retreives only the list of tables that are considered 'base'
   * tables.
   *
   * @return
   *    An array of base table names.
   *
   * @ingroup tripal_chado_schema_api
   */
  public function getBaseTables() {

    // Initialize the base tables with those tables that are missing a type.
    // Ideally they should have a type, but that's for a future version of Chado.
    $base_tables = [
      'organism',
      'project',
      'analysis',
      'biomaterial',
      'eimage',
      'assay',
    ];

    // We'll use the cvterm table to guide which tables are base tables. Typically
    // base tables (with a few exceptions) all have a type.  Iterate through the
    // referring tables.
    $schema = $this->getTableSchema('cvterm');
    if (isset($schema['referring_tables'])) {
      foreach ($schema['referring_tables'] as $tablename) {

        // Ignore the cvterm tables, relationships, chadoprop tables.
        if ($tablename == 'cvterm_dbxref' || $tablename == 'cvterm_relationship' ||
          $tablename == 'cvtermpath' || $tablename == 'cvtermprop' || $tablename == 'chadoprop' ||
          $tablename == 'cvtermsynonym' || preg_match('/_relationship$/', $tablename) ||
          preg_match('/_cvterm$/', $tablename) ||
          // Ignore prop tables
          preg_match('/prop$/', $tablename) || preg_match('/prop_.+$/', $tablename) ||
          // Ignore nd_tables
          preg_match('/^nd_/', $tablename)) {
          continue;
        }
        else {
          array_push($base_tables, $tablename);
        }
      }
    }

    // Remove any linker tables that have snuck in.  Linker tables are those
    // whose foreign key constraints link to two or more base table.
    $final_list = [];
    foreach ($base_tables as $i => $tablename) {
      // A few tables break our rule and seems to look
      // like a linking table, but we want to keep it as a base table.
      if ($tablename == 'biomaterial' or $tablename == 'assay' or $tablename == 'arraydesign') {
        $final_list[] = $tablename;
        continue;
      }

      // Remove the phenotype table. It really shouldn't be a base table as
      // it is meant to store individual phenotype measurements.
      if ($tablename == 'phenotype') {
        continue;
      }
      $num_links = 0;
      $schema = $this->getTableSchema($tablename);
      $fkeys = $schema['foreign keys'];
      foreach ($fkeys as $fkid => $details) {
        $fktable = $details['table'];
        if (in_array($fktable, $base_tables)) {
          $num_links++;
        }
      }
      if ($num_links < 2) {
        $final_list[] = $tablename;
      }
    }

    // Now add in the cvterm table to the list.
    $final_list[] = 'cvterm';

    // Sort the tables and return the list.
    sort($final_list);
    return $final_list;

  }

  /**
   * Retrieve schema details from YAML file.
   *
   * @return
   *   An array with details for the current schema version.
   */
  public function getSchemaDetails() {

    if (empty($this->schema)) {
      $filename = drupal_get_path('module', 'tripal_chado') . '/chado_schema/chado_schema-1.3.yml';
      $this->schema = Yaml::parse(file_get_contents($filename));
    }

    return $this->schema;
  }

  /**
   * Get information about which Chado base table a cvterm is mapped to.
   *
   * Vocbulary terms that represent content types in Tripal must be mapped to
   * Chado tables.  A cvterm can only be mapped to one base table in Chado.
   * This function will return an object that contains the chado table and
   * foreign key field to which the cvterm is mapped.  The 'chado_table'
   * property of the returned object contains the name of the table, and the
   * 'chado_field' property contains the name of the foreign key field (e.g.
   * type_id), and the
   * 'cvterm' property contains a cvterm object.
   *
   * @params
   *   An associative array that contains the following keys:
   *     - cvterm_id:  the cvterm ID value for the term.
   *     - vocabulary: the short name for the vocabulary (e.g. SO, GO, PATO)
   *     - accession:  the accession for the term.
   *     - bundle_id:  the ID for the bundle to which a term is associated.
   *   The 'vocabulary' and 'accession' must be used together, the 'cvterm_id'
   *   can be used on it's own.
   *
   * @return
   *   An object containing the chado_table and chado_field properties or NULL
   *   if if no mapping was found for the term.
   *
  public function getCvtermMapping($params) {
    return chado_get_cvterm_mapping($params);
  }*/

  /**
   * Check that any given Chado table exists.
   *
   * This function is necessary because Drupal's db_table_exists() function will
   * not look in any other schema but the one where Drupal is installed
   *
   * @param $table
   *   The name of the chado table whose existence should be checked.
   *
   * @return
   *   TRUE if the table exists in the chado schema and FALSE if it does not.
   */
  public function checkTableExists($table) {

    // Get the default database and chado schema.
    $default_db = $this->default_db;
    $chado_schema = $this->schema_name;

    // If we've already lookup up this table then don't do it again, as
    // we don't need to keep querying the database for the same tables.
    if (array_key_exists("chado_tables", $GLOBALS) and
      array_key_exists($default_db, $GLOBALS["chado_tables"]) and
      array_key_exists($chado_schema, $GLOBALS["chado_tables"][$default_db]) and
      array_key_exists($table, $GLOBALS["chado_tables"][$default_db][$chado_schema])) {
      return TRUE;
    }

    $sql = "
      SELECT 1
      FROM information_schema.tables
      WHERE
        table_name = :table_name AND
        table_schema = :chado AND
        table_catalog = :default_db
    ";
    $args = [
      ':table_name' => strtolower($table),
      ':chado' => $chado_schema,
      ':default_db' => $default_db,
    ];
    $query = $this->connection->query($sql, $args);
    $results = $query->fetchAll();
    if (empty($results)) {
      return FALSE;
    }

    // Set this table in the GLOBALS so we don't query for it again the next time.
    $GLOBALS["chado_tables"][$default_db][$chado_schema][$table] = TRUE;
    return TRUE;
  }

  /**
   * Check that any given column in a Chado table exists.
   *
   * This function is necessary because Drupal's db_field_exists() will not
   * look in any other schema but the one were Drupal is installed
   *
   * @param $table
   *   The name of the chado table.
   * @param $column
   *   The name of the column in the chado table.
   *
   * @return
   *   TRUE if the column exists for the table in the chado schema and
   *   FALSE if it does not.
   *
   * @ingroup tripal_chado_schema_api
   */
  public function checkColumnExists($table, $column) {

    // Get the default database and chado schema.
    $default_db = $this->default_db;
    $chado_schema = $this->schema_name;

    // @upgrade $cached_obj = cache_get('chado_table_columns', 'cache');
    // if ($cached_obj) {
    //   $cached_cols = $cached_obj->data;
    //   if (is_array($cached_cols) and
    //     array_key_exists($table, $cached_cols) and
    //     array_key_Exists($column, $cached_cols[$table])) {
    //     return $cached_cols[$table][$column]['exists'];
    //   }
    // }

    $sql = "
      SELECT 1
      FROM information_schema.columns
      WHERE
        table_name = :table_name AND
        column_name = :column_name AND
        table_schema = :chado AND
        table_catalog = :default_db
    ";
    $args = [
      ':table_name' => strtolower($table),
      ':column_name' => $column,
      ':chado' => $chado_schema,
      ':default_db' => $default_db,
    ];
    $query = $this->connection->query($sql, $args);
    $results = $query->fetchAll();
    if (empty($results)) {
      // @upgrade $cached_cols[$table][$column]['exists'] = FALSE;
      // cache_set('chado_table_columns', $cached_cols, 'cache', CACHE_TEMPORARY);
      return FALSE;
    }

    // @upgrade $cached_cols[$table][$column]['exists'] = TRUE;
    // cache_set('chado_table_columns', $cached_cols, 'cache', CACHE_TEMPORARY);
    return TRUE;
  }

  /**
   * Check that any given column in a Chado table exists.
   *
   * This function is necessary because Drupal's db_field_exists() will not
   * look in any other schema but the one were Drupal is installed
   *
   * @param $table
   *   The name of the chado table.
   * @param $column
   *   The name of the column in the chado table.
   * @param $type
   *   (OPTIONAL) The PostgreSQL type to check for. If not supplied it will be
   *   looked up via the schema (PREFERRED).
   *
   * @return
   *   TRUE if the column type matches what we expect and
   *   FALSE if it does not.
   *
   * @ingroup tripal_chado_schema_api
   */
  public function checkColumnType($table, $column, $expected_type = NULL) {

    // Ensure this column exists before moving forward.
    if (!$this->checkColumnExists($table, $column)) {
      tripal_report_error(
        'ChadoSchema',
        TRIPAL_WARNING,
        'Unable to check the type of !table!column since it doesn\'t appear to exist in your site database.',
        ['!column' => $column, '!table' => $table]
      );
      return FALSE;
    }

    // Look up the type using the Schema array.
    if ($expected_type === NULL) {
      $schema = $this->getTableSchema($table, $column);

      if (is_array($schema) AND isset($schema['fields'][$column])) {
        $expected_type = $schema['fields'][$column]['type'];
      }
      else {
        tripal_report_error(
          'ChadoSchema',
          TRIPAL_WARNING,
          'Unable to check the type of !table!column due to being unable to find the schema definition.',
          ['!column' => $column, '!table' => $table]
        );
        return FALSE;
      }
    }

    // There is some flexibility in the expected type...
    // Fix that here.
    switch ($expected_type) {
      case 'int':
        $expected_type = 'integer';
        break;
      case 'serial':
        $expected_type = 'integer';
        break;
      case 'varchar':
        $expected_type = 'character varying';
        break;
      case 'datetime':
        $expected_type = 'timestamp without time zone';
        break;
      case 'char':
        $expected_type = 'character';
        break;
    }

    // Grab the type from the current database.
    $query = 'SELECT data_type
              FROM information_schema.columns
              WHERE
                table_name = :table AND
                column_name = :column AND
                table_schema = :schema
              ORDER  BY ordinal_position
              LIMIT 1';
    $type = $this->connection->query($query,
      [
        ':table' => $table,
        ':column' => $column,
        ':schema' => $this->schema_name,
      ])->fetchField();

    // Finally we do the check!
    if ($type === $expected_type) {
      return TRUE;
    }
    elseif (($expected_type == 'float') AND (($type == 'double precision') OR ($type == 'real'))) {
      return TRUE;
    }
    elseif ($type == 'smallint' AND $expected_type == 'integer') {
      return TRUE;
    }
    elseif ($type == 'bigint' AND $expected_type == 'integer') {
      return TRUE;
    }
    else {
      return FALSE;
    }
  }

  /**
   * Check that any given sequence in a Chado table exists.
   *
   * @param table
   *   The name of the table the sequence is used in.
   * @param column
   *   The name of the column the sequence is used to populate.
   *
   * @return
   *   TRUE if the seqeuence exists in the chado schema and FALSE if it does
   *   not.
   *
   * @ingroup tripal_chado_schema_api
   */
  public function checkSequenceExists($table, $column, $sequence_name = NULL) {

    $prefixed_table = $this->schema_name . '.' . $table;
    if ($sequence_name === NULL) {
      $sequence_name = $this->connection->query('SELECT pg_get_serial_sequence(:table, :column);',
        [':table' => $prefixed_table, ':column' => $column])->fetchField();

      // Remove prefixed table from sequence name
      $sequence_name = str_replace($this->schema_name . '.', '', $sequence_name);
    }

    // Get the default database and chado schema.
    $default_db = $this->default_db;
    $chado_schema = $this->schema_name;

    // @upgrade $cached_obj = cache_get('chado_sequences', 'cache');
    // $cached_seqs = $cached_obj->data;
    // if (is_array($cached_seqs) and array_key_exists($sequence, $cached_seqs)) {
    //  return $cached_seqs[$sequence]['exists'];
    // }

    $sql = "
      SELECT 1
      FROM information_schema.sequences
      WHERE
        sequence_name = :sequence_name AND
        sequence_schema = :sequence_schema AND
        sequence_catalog = :sequence_catalog
    ";
    $args = [
      ':sequence_name' => strtolower($sequence_name),
      ':sequence_schema' => $chado_schema,
      ':sequence_catalog' => $default_db,
    ];
    $query = $this->connection->query($sql, $args);
    $results = $query->fetchAll();
    if (empty($results)) {
      // @upgrade $cached_seqs[$sequence]['exists'] = FALSE;
      // cache_set('chado_sequences', $cached_seqs, 'cache', CACHE_TEMPORARY);
      return FALSE;
    }
    // @upgrade $cached_seqs[$sequence]['exists'] = FALSE;
    // cache_set('chado_sequences', $cached_seqs, 'cache', CACHE_TEMPORARY);
    return TRUE;
  }

  /**
   * Check that the primary key exists, has a sequence and a constraint.
   *
   * @param $table
   *   The table you want to check the primary key for.
   * @param $column
   *   (OPTIONAL) The name of the primary key column.
   *
   * @return
   *   TRUE if the primary key meets all the requirements and false otherwise.
   */
  public function checkPrimaryKey($table, $column = NULL) {

    // If they didn't supply the column, then we can look it up.
    if ($column === NULL) {
      $table_schema = $this->getTableSchema($table);
      $column = $table_schema['primary key'][0];
    }

    // If there is no primary key then we can't check it.
    // It neither passes nore fails validation.
    if (empty($column)) {
      tripal_report_error(
        'ChadoSchema',
        TRIPAL_NOTICE,
        'Cannot check the validity of the primary key for ":table" since there is no record of one.',
        [':table' => $table]
      );
      return NULL;
    }

    // Check the column exists.
    $column_exists = $this->checkColumnExists($table, $column);
    if (!$column_exists) {
      return FALSE;
    }

    // First check that the sequence exists.
    $sequence_exists = $this->checkSequenceExists($table, $column);
    if (!$sequence_exists) {
      return FALSE;
    }

    // Next check the constraint is there.
    $constraint_exists = $this->connection->query(
      "SELECT 1
      FROM information_schema.table_constraints
      WHERE table_name=:table AND constraint_type = 'PRIMARY KEY'",
      [':table' => $table])->fetchField();
    if (!$constraint_exists) {
      return FALSE;
    }

    return TRUE;
  }

  /**
   * Check that the constraint exists.
   *
   * @param $table
   *   The table the constraint applies to.
   * @param $constraint_name
   *   The name of the constraint you want to check.
   * @param $type
   *   The type of constraint. Should be one of "PRIMARY KEY", "UNIQUE", or
   *   "FOREIGN KEY".
   *
   * @return
   *   TRUE if the constraint exists and false otherwise.
   */
  public function checkConstraintExists($table, $constraint_name, $type) {

    // Next check the constraint is there.
    $constraint_exists = $this->connection->query(
      "SELECT 1
      FROM information_schema.table_constraints
      WHERE table_name=:table AND constraint_type = :type AND constraint_name = :name",
      [
        ':table' => $table,
        ':name' => $constraint_name,
        ':type' => $type,
      ])->fetchField();
    if (!$constraint_exists) {
      return FALSE;
    }

    return TRUE;
  }

  /**
   * Check the foreign key constrain specified exists.
   *
   * @param $base_table
   *   The name of the table the foreign key resides in. E.g. 'feature' for
   *     the feature.type_id => cvterm.cvterm_id foreign key.
   * @param $base_column
   *   The name of the column that is a foreign key in. E.g. 'type_id' for
   *     the feature.type_id => cvterm.cvterm_id foreign key.
   *
   * @return
   *   TRUE if the constraint exists and false otherwise.
   */
  public function checkFKConstraintExists($base_table, $base_column) {


    // Since we don't have a constraint name, we have to use the known pattern for
    // creating these names in order to make this check.
    // This is due to PostgreSQL not storing column information for constraints
    // in the information_schema tables.
    $constraint_name = $base_table . '_' . $base_column . '_fkey';

    return $this->checkConstraintExists($base_table, $constraint_name, 'FOREIGN KEY');
  }

  /**
   * A Chado-aware replacement for the db_index_exists() function.
   *
   * @param string $table
   *   The table to be altered.
   * @param string $name
   *   The name of the index.
   * @param bool $no_suffix
   */
  public function checkIndexExists($table, $name, $no_suffix = FALSE) {

    if ($no_suffix) {
      $indexname = strtolower($table . '_' . $name);
    }
    else {
      $indexname = strtolower($table . '_' . $name . '_idx');
    }

    // Get the default database and chado schema.
    $default_db = $this->default_db;
    $chado_schema = $this->schema_name;

    $sql = "
      SELECT 1 as exists
      FROM pg_indexes
      WHERE
        indexname = :indexname AND
        tablename = :tablename AND
        schemaname = :schemaname
    ";
    $args = [
      ':indexname' => $indexname,
      ':tablename' => strtolower($table),
      ':schemaname' => $chado_schema,
    ];
    $query = $this->connection->query($sql, $args);
    $results = $query->fetchAll();
    if (empty($results)) {
      return FALSE;
    }
    return TRUE;
  }

  /**
   * A Chado-aware replacement for db_add_index().
   *
   * @param $table
   *   The table to be altered.
   * @param $name
   *   The name of the index.
   * @param string $fields
   *   An array of field names.
   */
  public function addIndex($table, $name, $fields, $no_suffix = FALSE) {

     if ($no_suffix) {
       $indexname = strtolower($table . '_' . $name);
     }
     else {
       $indexname = strtolower($table . '_' . $name . '_idx');
     }

     // Get the default database and chado schema.
     $default_db = $this->default_db;
     $chado_schema = $this->schema_name;
     $chado_dot = $chado_schema . '.';

     // Determine the create index SQL command.
     // Note: we dont use place holders here because we cannot
     // have quotes around thse parameters.
     $query = 'CREATE INDEX "' . $indexname . '" ON ' . $chado_dot . $table . ' ';
     $query .= '(';
     $temp = [];
     foreach ($fields as $field) {
       if (is_array($field)) {
         $temp[] = 'substr(' . $field[0] . ', 1, ' . $field[1] . ')';
       }
       else {
         $temp[] = '"' . $field . '"';
       }
     }
     $query .= implode(', ', $temp);
     $query .= ')';

     // Now execute it!
     return $this->connection->query($query);
   }

  /**
   * Return table dependencies.
   *
   * @TODO: migrate to Chado API
   *
   * @param $chado_schema
   *   Name of the schema to process.
   *
   * @return array
   *   first-level keys are table name, second level keys are column names,
   *   third level keys are foreign table names and values are foreign column
   *   names.
   */
  protected function getTableDependencies($chado_schema) {
    $connection = $this->connection;
    // Get tables.
    $sql_query = "
      SELECT
        DISTINCT c.relname
      FROM
        pg_class c
        JOIN pg_namespace n ON (
          n.oid = c.relnamespace
          AND n.nspname = :schema
        )
      WHERE
        c.relkind IN ('r','p')
        AND c.relpersistence = 'p'
      ORDER BY c.relkind DESC, c.relname
    ";
    $tables = $connection
      ->query($sql_query, [':schema' => $chado_schema])
      ->fetchAllAssoc('relname')
    ;

    $table_dependencies = [];
    // Process all tables.
    foreach ($tables as $table_name => $table) {
      $table_dependencies[$table_name] = [];

      // Get new table definition.
      $sql_query = "SELECT public.tripal_get_table_ddl('$chado_schema', '$table_name', TRUE) AS \"definition\";";
      $table_raw_definition = explode("\n", $connection->query($sql_query)->fetch()->definition);
      $table_definition = $this->parseSchemaTableDdl($table_raw_definition);

      // Process FK constraints.
      $foreign_keys = [];
      $cstr_defs = $table_definition['constraints'];
      foreach ($cstr_defs as $constraint_name => $constraint_def) {
        if (preg_match(
              '/
                # Match "FOREIGN KEY ("
                FOREIGN\s+KEY\s*\(
                   # Capture current table columns (one or more).
                  (
                    (?:[\w\$\x80-\xFF\.]+\s*,?\s*)+
                  )
                \)\s*
                # Match "REFERENCES"
                REFERENCES\s*
                  # Caputre evental schema name.
                  ([\w\$\x80-\xFF]+\.|)
                  # Caputre foreign table name.
                  ([\w\$\x80-\xFF]+)\s*
                  \(
                    # Capture foreign table columns (one or more).
                    (
                      (?:[\w\$\x80-\xFF]+\s*,?\s*)+
                    )
                  \)
              /ix',
              $constraint_def,
              $match
            )
        ) {
          $table_columns =  preg_split('/\s*,\s*/', $match[1]);
          $foreign_table_schema = $match[2];
          $foreign_table = $match[3];
          $foreign_table_columns =  preg_split('/\s*,\s*/', $match[4]);
          if (count($table_columns) != count($foreign_table_columns)) {
            throw new \Exception("Failed to parse foreign key definition for table '$table_name':\n'$constraint_def'");
          }
          else {
            for ($i = 0; $i < count($table_columns); ++$i) {
              $table_dependencies[$table_name][$table_columns[$i]] = [
                $foreign_table => $foreign_table_columns[$i],
              ];
            }
          }
        }
      }
    }
    return $table_dependencies;
  }

}
