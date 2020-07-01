<?php

namespace Drupal\Tests\bos311\Unit;

use Drupal\Tests\UnitTestCase;
use Drupal\bos311\Record;

class ValidationTest extends UnitTestCase {

  public function testValidateDateTime() {
    // I ended up changing to to a format method, so this is vestigial (for now).
    $this->assertTrue(Record::formatDateTime('2000-01-01T01:00:00+1200'));
    $this->assertTrue(Record::formatDateTime('2020-05-18T18:19:14-0400'));
    $this->assertTrue(Record::formatDateTime('2020-05-18T18:19:14-04:00'));
    $this->assertTrue(Record::formatDateTime('2020-05-18T18:19:14'));
    $this->assertFalse(Record::formatDateTime('1234567890')); // Possible known bad format

  }

}
