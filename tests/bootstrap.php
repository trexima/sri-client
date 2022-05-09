<?php

require_once __DIR__.'/../vendor/autoload.php';

$env = new \Symfony\Component\Dotenv\Dotenv();
$env->load(__DIR__.'/../.env');

if (!array_key_exists('SRI_URL', $_ENV)) {
    $_ENV['SRI_URL'] = getenv('SRI_URL');
}
if (!array_key_exists('SRI_API_KEY', $_ENV)) {
    $_ENV['SRI_API_KEY'] = getenv('SRI_API_KEY');
}
if (!array_key_exists('TEST_ORGANIZATION_ID', $_ENV)) {
    $_ENV['TEST_ORGANIZATION_ID'] = getenv('TEST_ORGANIZATION_ID');
}