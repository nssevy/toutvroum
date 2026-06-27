<?php

require_once __DIR__ . '/../vendor/autoload.php';

// Chargement .env
$dotenv = Dotenv\Dotenv::createImmutable(__DIR__ . '/..');
$dotenv->load();

if (session_status() === PHP_SESSION_NONE) {
    session_start();
}

// Twig
$twig = require __DIR__ . '/twig.php';
