<?php

require dirname(__DIR__).'/vendor/autoload.php';

define('AOP_TEST_RUN', true);

$root = dirname(__DIR__);
if (is_file($root.'/bootstrap/cache/config.php') || glob($root.'/bootstrap/cache/routes-*.php')) {
    throw new RuntimeException('Tests refused: cached configuration or routes exist. Use an isolated, uncached checkout.');
}

if (is_file($root.'/.env')) {
    $environment = Dotenv\Dotenv::parse(file_get_contents($root.'/.env'));
    if (($environment['APP_ENV'] ?? '') === 'production') {
        throw new RuntimeException('Tests refused: this checkout has a production .env.');
    }
}
