<?php

declare(strict_types=1);

// Loads the sonda classes when the measured project does not have sonda installed
// through Composer. Its dependencies (nikic/php-parser, phpunit) come from the project.
spl_autoload_register(static function (string $class): void {
    $prefix = 'Sonda\\';

    if (!str_starts_with($class, $prefix)) {
        return;
    }

    $file = __DIR__ . '/src/' . str_replace('\\', '/', substr($class, strlen($prefix))) . '.php';

    if (is_file($file)) {
        require $file;
    }
});
