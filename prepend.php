<?php

declare(strict_types=1);

// The auto_prepend_file of a test process that sonda started again (see Sonda\Restart): it runs
// before the test runner requires Composer's autoloader, so the helpers of Composer's `files` and
// the classes that the runner loads while it starts come instrumented. The bootstrap takes over
// when the runner reaches it.

use Sonda\{ComposerFiles, Settings};

require __DIR__ . '/autoload.php';

ComposerFiles::intercept(Settings::fromEnvironment(getenv()));

// The auto_prepend_file of the user's own PHP configuration, which the restart replaced.
if ((string) getenv('SONDA_PREPEND_CHAIN') !== '') {
    require getenv('SONDA_PREPEND_CHAIN');
}
