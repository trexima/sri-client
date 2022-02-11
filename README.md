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