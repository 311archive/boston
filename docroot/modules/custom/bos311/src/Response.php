<?php

namespace Drupal\bos311;

use GuzzleHttp\Client;
use Psr\Http\Message\ResponseInterface;
use GuzzleHttp\Exception\ServerException;

class Response {

  public static function fetch($url, $retryOnError = 10)
  {
    $client = new Client([ 'curl' => [CURLOPT_SSL_VERIFYPEER => false, ], ]);
    try {
       /* @var $response ResponseInterface $response */
      $options = [
          'referer' => true,
          'headers' => [
              'User-Agent' => 'bos311app/v1.0',
              'Accept' => 'text/html,application/xhtml+xml,application/xml;q=0.9,image/webp,image/apng,*/*;q=0.8',
              'Accept-Encoding' => 'gzip, deflate, br',
          ]
      ];
      $response = $client->get($url, $options);
      $body = $response->getBody();
      $body = \GuzzleHttp\json_decode($body);
      return $body;
    }
    catch (ServerException $e) {
      if ($retryOnError) {
        $retryOnError--;
          usleep(250000);
        return self::fetch($url, $retryOnError);
      }
      echo 'Caught response: ' . $e->getResponse()->getStatusCode();
    }
  }

}
