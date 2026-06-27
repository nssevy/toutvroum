<?php

$loader = new \Twig\Loader\FilesystemLoader(__DIR__ . '/../templates');

return new \Twig\Environment($loader, [
    // 'cache' => __DIR__ . '/../var/cache/twig',
    'debug' => ($_ENV['APP_DEBUG'] ?? 'false') === 'true',
]);
