<?php

namespace Drupal\bos311;

use GuzzleHttp\Client;
use Psr\Http\Message\ResponseInterface;
use GuzzleHttp\Exception\ServerException;

class Response {

  public static function fetch($url, $retryOnError = 10)
  {
    $client = new Client();
    try {
       /* @var $response ResponseInterface $response */
      $response = $client->get($url);
      $body = $response->getBody();
      $body = \GuzzleHttp\json_decode($body);
      return $body;
    }
    catch (ServerException $e) {
      if ($retryOnError) {
        $retryOnError--;
        return self::fetch($url, $retryOnError);
      }
      echo 'Caught response: ' . $e->getResponse()->getStatusCode();
    }
  }

}
