<?php

/**
 * @file
 * Contains \Drupal\collaboration_test\Tests\Core\simpletest\Collaboration\SimpletestGroupAlter.
 */

namespace Drupal\collaboration_test\Tests\Core\simpletest\Collaboration;

/**
 * Tests sanity of output after hook_simpletest_alter().
 */
class SimpletestGroupAlter {

  public function initiateBaseAlter() {
    return simpletest_test_get_all();
  }

  public function verify($pass, $data) {
    foreach ($data as $group) {
      foreach ($group as $test) {
        
      }
    }
  }
}