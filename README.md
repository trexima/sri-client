# sri-client

## Installation
Installation with Composer:
```
composer require trexima/sri-client
```

## Installation of MYSQL driver inside of Docker container
Installation inside of Docker container:
```
docker-php-ext-install pdo_mysql
docker-php-ext-enable pdo_mysql
/etc/init.d/apache2 reload
```

## Basic Usage of v2 client
```php
<?php

use GuzzleHttp\Exception\GuzzleException;
use Symfony\Component\Cache\Adapter\ArrayAdapter;
use Trexima\SriClient\Exception\GraphQLException;
use Trexima\SriClient\v2\Client;

require __DIR__.'/vendor/autoload.php'; // Composer's autoloader

// create client
$cache = new ArrayAdapter();
$parameterExtractor = new \Trexima\SriClient\MethodParameterExtractor($cache);
$sriClient = new Client('http://sri.localhost', '', $parameterExtractor, $cache);

// make request
try {
    $activity = $sriClient->getActivityDetail('1');
} catch (GuzzleException $e) {
    // TODO handle exception
}

var_dump($activity);

// make GraphQL request
try {
    $query = sprintf('
        {
            activitiesTimeline (id: \"/api/activities_timeline/%s\") {
                id,
                name,
                content,
                dateFrom,
                dateTo
            }
        }', 1);
    $graphQLquery = '{"query": "query ' . str_replace(array("\n", "\r"), '', $query) . '"}';
    $activity = $sriClient->getGraphQL($graphQLquery);
} catch (GraphQLException $e) {
    $errors = $e->getGraphQLErrors();
    $data = $e->getData();
    $message = $e->getMessage();
    // TODO handle GraphQL error
} catch (GuzzleException $e) {
    $message = $e->getMessage();
    $httpCode = $e->getCode();
    if ($e->hasResponse()) {
        $response = $e->getResponse();
    }
    // TODO handle Guzzle error
}

var_dump($activity);
```
