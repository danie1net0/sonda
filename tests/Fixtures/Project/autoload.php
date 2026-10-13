<?php

declare(strict_types=1);

$loader = require dirname(__DIR__, 3) . '/vendor/autoload.php';
$loader->addPsr4('Fixture\\Project\\', __DIR__ . '/src');
$loader->addPsr4('Fixture\\Project\\Tests\\', __DIR__ . '/tests');

return $loader;
