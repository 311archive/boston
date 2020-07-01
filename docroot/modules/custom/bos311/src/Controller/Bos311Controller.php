<?php

namespace Drupal\bos311\Controller;

use Drupal\bos311\FetchResponses;
use Drupal\Core\Controller\ControllerBase;
use Drupal\bos311\Record;

class Bos311Controller extends ControllerBase {

  public function bos311() {
    $fetch = new \Drupal\bos311\FetchResponses();
    $fetch->doFetchRecords();

    $element = [
      "#markup" => "<h2>Hello (boston) world ;)</h2>"
    ];
    return $element;
  }

  public function bos311Old() {


    $element = [
      "#markup" => "<h2>Hello (boston) world ;)</h2>"
    ];
    return $element;
  }

}
