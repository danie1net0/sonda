<?php

declare(strict_types=1);

use Illuminate\Container\Container;
use Illuminate\Filesystem\Filesystem;
use Illuminate\View\Compilers\BladeCompiler;
use Sonda\Blade\Views;
use Sonda\{Instrumenter, Settings};
use Sonda\PhpUnit\PreparedSubscriber;
use PHPUnit\Event\Test\Prepared;

afterEach(function (): void {
    putenv('VIEW_COMPILED_PATH');
    unset($_ENV['VIEW_COMPILED_PATH'], $_SERVER['VIEW_COMPILED_PATH']);
    Container::setInstance();
    Views::configure(new Settings('a', ['a'], [], [], 'c', 'o', true, true, null), new Instrumenter());
});

function preparedEvent(): Prepared
{
    return new ReflectionClass(Prepared::class)->newInstanceWithoutConstructor();
}

it('prepares the views with the application a test has booted', function (): void {
    $root = TemporaryDirectory::create();
    mkdir($root . '/views');
    mkdir($root . '/runs');
    file_put_contents($root . '/views/a.blade.php', "{{ \$a }}\n");
    $views = Views::configure(new Settings($root . '/vendor/autoload.php', [$root . '/views'], [], [], $root . '/cache', $root . '/runs', true, true, null, [$root . '/views']), new Instrumenter());
    $application = new Container();
    $application->instance('blade.compiler', new BladeCompiler(new Filesystem(), (string) getenv('VIEW_COMPILED_PATH')));
    Container::setInstance($application);

    new PreparedSubscriber()->notify(preparedEvent());

    expect(array_column($views->files(), 0))->toBe([$root . '/views/a.blade.php']);
});

it('does nothing when no views were configured', function (): void {
    Views::configure(new Settings('a', ['a'], [], [], 'c', 'o', true, true, null), new Instrumenter());
    Container::setInstance();

    new PreparedSubscriber()->notify(preparedEvent());

    expect(Container::getInstance()->bound('blade.compiler'))->toBeFalse();
});
