<?php

namespace Drupal\Tests\bos311\Unit;

use Drupal\Tests\UnitTestCase;
use Drupal\bos311\Record;

class ValidationTest extends UnitTestCase {

  public function testFormatLatLong() {
      $expected = '1.2722218725854';

      $string = '1.2722218725854E-14';
      $processed = Record::formatLatLong($string);
      $this->assertEquals($expected, $processed);

      $string = '1.2722218725854';
      $processed = Record::formatLatLong($string);
      $this->assertEquals($expected, $processed);
  }

}
