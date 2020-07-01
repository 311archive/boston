<?php

namespace Drupal\Tests\bos311\Kernel;

use \Drupal\KernelTests\KernelTestBase;
use Drupal\bos311\Record;

class CreateEntityTest extends KernelTestBase {

  /**
   * {@inheritdoc}
   */
  protected static $modules = ['bos311', 'node', 'user', 'system'];

  protected $record;

  public function testCreate() {

    $this->assertEqual(2, 2);
  }

}
