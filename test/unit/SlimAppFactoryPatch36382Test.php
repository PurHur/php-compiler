<?php

declare(strict_types=1);

namespace PHPCompiler\Test\Unit;

use PHPUnit\Framework\TestCase;

/**
 * #36382 — Slim AppFactory::create arg-spill patch.
 */
final class SlimAppFactoryPatch36382Test extends TestCase
{
    public function testPatchIsIdempotentAndSpillsCreateArgs(): void
    {
        $root = dirname(__DIR__, 2);
        $tmp = sys_get_temp_dir().'/AppFactory_36382_'.getmypid().'.php';
        $seed = <<<'PHP'
<?php
namespace Slim\Factory;
class AppFactory
{
    public static function create(
        $responseFactory = null,
        $container = null,
        $callableResolver = null,
        $routeCollector = null,
        $routeResolver = null,
        $middlewareDispatcher = null
    ): App {
        static::$responseFactory = $responseFactory ?? static::$responseFactory;
        return new App(
            self::determineResponseFactory(),
            $container ?? static::$container,
            $callableResolver ?? static::$callableResolver,
            $routeCollector ?? static::$routeCollector,
            $routeResolver ?? static::$routeResolver,
            $middlewareDispatcher ?? static::$middlewareDispatcher
        );
    }
}
PHP;
        file_put_contents($tmp, $seed);
        $script = $root.'/script/composer/patch-slim-appfactory-36382.php';
        exec(escapeshellarg(PHP_BINARY).' '.escapeshellarg($script).' '.escapeshellarg($tmp).' 2>&1', $out, $rc);
        $this->assertSame(0, $rc, implode("\n", $out));
        $patched = file_get_contents($tmp);
        $this->assertNotFalse($patched);
        $this->assertStringContainsString('spill AppFactory::create args', $patched);
        $this->assertStringContainsString('$resolvedResponseFactory = self::determineResponseFactory()', $patched);
        $this->assertStringContainsString('return new App(', $patched);
        $this->assertStringContainsString('$resolvedMiddlewareDispatcher', $patched);
        exec(escapeshellarg(PHP_BINARY).' '.escapeshellarg($script).' '.escapeshellarg($tmp).' 2>&1', $out2, $rc2);
        $this->assertSame(0, $rc2, implode("\n", $out2));
        $this->assertStringContainsString('already patched', implode("\n", $out2));
        @unlink($tmp);
    }
}
