<?php

declare(strict_types=1);

// Loads the sonda classes from this copy of sonda, ahead of Composer's autoloader: the measured
// project may not have sonda installed, or may have another version of it, and one process must
// not mix the two. Its dependencies (nikic/php-parser, phpunit) come from the project.
spl_autoload_register(static function (string $class): void {
    $prefix = 'Sonda\\';

    if (! str_starts_with($class, $prefix)) {
        return;
    }

    $file = __DIR__ . '/src/' . str_replace('\\', '/', substr($class, strlen($prefix))) . '.php';

    if (is_file($file)) {
        require $file;
    }
}, prepend: true);
