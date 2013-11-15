<?php

/**
 * @file
 * Contains DrupalCollaborationTestCase.
 */

/**
 * Base testing class for collaboration tests.
 *
 * Collaboration tests seek to ensure that modules enabled on the current site
 * collaborate sanely.
 */
abstract class DrupalCollaborationTestCase extends DrupalTestCase {
  /**
   * An array of collaborating test objects. Only populated in the test leader.
   *
   * @var DrupalCollaborationTestCase[]
   */
  private $collaborators;

  /**
   * The leader of this collaboration test run.
   *
   * The leader test is the entry point that was selected for the test run. It
   * coordinates the other test cases involved in the run and collects all test
   * results.
   *
   * @var DrupalCollaborationTestCase
   */
  private $leader;

  /**
   * Constructor for DrupalCollaborationTestCase.
   *
   * TODO figure out an appropriate test id...
   */
  public function __construct($test_id = NULL, DrupalCollaborationTestCase $leader) {
    parent::__construct($test_id);
    $this->leader = $leader;
    $this->skipClasses[__CLASS__] = TRUE;
  }

  /**
   * Gets the leader of this test run.
   *
   * @return DrupalCollaborationTestCase
   */
  public function leader() {
    return $this->leader;
  }

  /**
   * {@inheritdoc}
   *
   * Redirects assertions to the test leader.
   */
  public function assert($status, $message = '', $group = 'Other', array $caller = NULL) {
    return $this->leader() === $this ?
      parent::assert($status, $message, $group, $caller) :
      $this->leader()->assert($status, $message, $group, $caller);
  }

  /**
   * Run the collaboration test represented by this class.
   *
   * TODO consider allowing an $extensions arg to be passed that turns off certain extensions
   */
  public function run() {
    if (!empty($this->leader) && $this->leader !== $this) {
      throw new \LogicException('Can only run the test if we are the leader, or a leader has yet to be designated.');
    }

    // Set this object as the test leader.
    $this->leader = $this;

    // Initialize verbose debugging.
    $class = get_class($this);
    //simpletest_verbose(NULL, variable_get('file_public_path', conf_path() . '/files'), str_replace('\\', '_', $class));
    set_error_handler(array($this, 'errorHandler'));

    $this->collaborators = array($this);
    // Select all PSR-0 classes in the Tests namespace of all enabled modules.
    $system_list = db_query("SELECT name, filename FROM {system} WHERE status = 1")->fetchAllKeyed();
    foreach ($system_list as $extension => $filename) {
      // Let simpletest's PSR-0 autoloader find classes for us.
      $file = "Drupal\\$extension\\Tests\\Collaboration\\$class";
      if (class_exists($file)) {
        $this->collaborators[$extension] = new $file($this->testId, $this);
      }
    }

    // Search all collaborators for initiator and verifier methods.
    $initiators = $verifiers = array();
    foreach ($this->collaborators as $extension => $collaborator) {
      $class_methods = get_class_methods($class);
      foreach ($class_methods as $method) {
        // If the current method starts with "initiate", it provides a
        // permutation to test across the verifier set.
        if (strtolower(substr($method, 0, 8)) == 'initiate') {
          // Save the method, collaborator, and extension for later.
          $initiators[] = array($collaborator, $method, $extension);

        }
        else if ($method == 'verify') {
          $verifiers[$extension] = $collaborator;
        }
      }
    }

    if (empty($initiators)) {
      throw new \LogicException(sprintf('No initiators found for test %s; cannot run the test.', $class));
    }

    foreach ($initiators as $initiator) {
      list($collaborator, $method, $extension) = $initiator;

      // Insert a fail record. This will be deleted on completion to ensure
      // that testing completed.
      // TODO fix AAAALL this to target each call in succession
      $method_info = new ReflectionMethod($collaborator, $method);
      $caller = array(
        'file' => $method_info->getFileName(),
        'line' => $method_info->getStartLine(),
        'function' => $class . '->' . $method . '()',
      );

      $initiator_cc_id = DrupalTestCase::insertAssert($this->testId, $class, FALSE, t('The initiator did not complete due to a fatal error.'), 'Completion check', $caller);

      // Keep all the setUp state on this object, don't call the collaborator.
      $this->setUp();
      if ($this->setup) {
        try {
          // Run the initiator method.
          $state = $collaborator->$method();

          // Run all verifiers on the result.
          foreach ($verifiers as $v_extension => $v_collaborator) {
            // Provide the verifier the id of the initiator (by substringing the
            // remainder of the method name after 'initiate') and the data
            $v_collaborator->verify(substr($method, 8), $state);
          }
        }
        catch (Exception $e) {
          // TODO make properly this sensitive to which method we're calling
          $this->exceptionHandler($e);
        }

        $this->tearDown();
      }
      else {
        $this->fail(t("The test cannot be executed because it has not been set up properly."));
      }
      // Remove the completion check record.
      DrupalTestCase::deleteAssert($initiator_cc_id);
    }
    // Clear out the error messages and restore error handler.
    drupal_get_messages();
    restore_error_handler();
  }

  /**
   * Sets up a collaboration test run environment.
   *
   * This is marked final because the typical "setup" responsibility has
   * inherently been shifted into initiator methods. Allowing normal test
   * classes to implement it would simply be confusing, as their setUp() methods
   * would not always be called.
   *
   * TODO consider refactoring this to call ALL collaborators' setUp() methods, then un-final it.
   */
  final protected function setUp() {
    global $conf;

    // Store necessary current values before switching to the test environment.
    $this->originalFileDirectory = variable_get('file_public_path', conf_path() . '/files');

    // Reset all statics; they can and should be regenerated as needed.
    drupal_static_reset();

    // Generate temporary prefixed database to ensure that tests have a clean starting point.
    $this->databasePrefix = Database::getConnection()->prefixTables('{simpletest' . mt_rand(1000, 1000000) . '}');

    // Create test directory.
    $public_files_directory = $this->originalFileDirectory . '/simpletest/' . substr($this->databasePrefix, 10);
    file_prepare_directory($public_files_directory, FILE_CREATE_DIRECTORY | FILE_MODIFY_PERMISSIONS);
    $conf['file_public_path'] = $public_files_directory;

    // Clone the current connection and replace the current prefix.
    $connection_info = Database::getConnectionInfo('default');
    Database::renameConnection('default', 'simpletest_original_default');
    foreach ($connection_info as $target => $value) {
      $connection_info[$target]['prefix'] = array(
        'default' => $value['prefix']['default'] . $this->databasePrefix,
      );
    }
    Database::addConnectionInfo('default', 'default', $connection_info['default']);

    // Set user agent to be consistent with web test case.
    $_SERVER['HTTP_USER_AGENT'] = $this->databasePrefix;

    $this->setup = TRUE;
  }

  protected function tearDown() {
    global $conf;

    // Get back to the original connection.
    Database::removeConnection('default');
    Database::renameConnection('simpletest_original_default', 'default');

    $conf['file_public_path'] = $this->originalFileDirectory;
  }

  protected function doAlter($hook) {
    // TODO provide alternate to drupal_alter() with introspection
  }

  protected function doInvoke($module, $hook) {
    // TODO provide alternate to module_invoke() with introspection
  }

  protected function doInvokeAll($hook) {
    // TODO provide alternate to module_invoke_all() with introspection
  }
}

