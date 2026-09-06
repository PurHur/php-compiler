<?php

declare(strict_types=1);

namespace PHPCompiler\Test\Unit;

use PHPUnit\Framework\TestCase;

/** #36382 — Slim App patch: instanceof on nullable routeResolver / callableResolver. */
final class SlimAppPatch36382Test extends TestCase
{
    public function testPatchIsIdempotentAndRewritesCoalesce(): void
    {
        $root = dirname(__DIR__, 2);
        $tmp = sys_get_temp_dir().'/App_36382_'.getmypid().'.php';
        $seed = <<<'PHP'
<?php
namespace Slim;
class App
{
    public function __construct(
        $responseFactory,
        $container = null,
        $callableResolver = null,
        $routeCollector = null,
        $routeResolver = null,
        $middlewareDispatcher = null
    ) {
        parent::__construct(
            $responseFactory,
            $callableResolver ?? new CallableResolver($container),
            $container,
            $routeCollector
        );

        $this->routeResolver = $routeResolver ?? new RouteResolver($this->routeCollector);
        $routeRunner = new RouteRunner($this->routeResolver, $this->routeCollector->getRouteParser(), $this);
    }
}
PHP;
        file_put_contents($tmp, $seed);
        $script = $root.'/script/composer/patch-slim-app-36382.php';
        exec(escapeshellarg(PHP_BINARY).' '.escapeshellarg($script).' '.escapeshellarg($tmp).' 2>&1', $out, $rc);
        $this->assertSame(0, $rc, implode("\n", $out));
        $patched = file_get_contents($tmp);
        $this->assertNotFalse($patched);
        $this->assertStringContainsString('instanceof RouteResolverInterface', $patched);
        $this->assertStringContainsString('instanceof CallableResolverInterface', $patched);
        $this->assertStringContainsString('AOT (#36382): typed nullable routeResolver', $patched);
        exec(escapeshellarg(PHP_BINARY).' '.escapeshellarg($script).' '.escapeshellarg($tmp).' 2>&1', $out2, $rc2);
        $this->assertSame(0, $rc2, implode("\n", $out2));
        $this->assertStringContainsString('already patched', implode("\n", $out2));
        @unlink($tmp);
    }
}
