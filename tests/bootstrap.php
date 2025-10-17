<?php

// tests/bootstrap.php

// 1. Load the Composer autoloader
require dirname(__DIR__) . '/vendor/autoload.php';

// 2. Load test-specific environment variables
if (file_exists(dirname(__DIR__) . '/.env.testing')) {
    $dotenv = new Symfony\Component\Dotenv\Dotenv();
    $dotenv->load(dirname(__DIR__) . '/.env.testing');
}