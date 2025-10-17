#!/usr/bin/env php
<?php
require dirname(__DIR__, 3) . '/autoload.php';

use Symfony\Component\Console\Application;
use Symfony\Component\Dotenv\Dotenv;
use Arffsaad\Qdiz\Console\WorkerCommand; 

if (class_exists(Dotenv::class) && file_exists(getcwd() . '/.env')) {
    $dotenv = new Dotenv();
    $dotenv->load(getcwd() . '/.env');
}

$application = new Application('Qdiz Worker');
$application->add(new WorkerCommand());

$application->setDefaultCommand('qdiz:work', true); 

$application->run();