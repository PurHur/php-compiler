<?php

declare(strict_types=1);

namespace PHPCompiler\Test\Unit;

use PHPUnit\Framework\TestCase;

/**
 * #36382 — Slim RouteCollector patch: instanceof on nullable strategy/parser defaults.
 */
final class SlimRouteCollectorPatch36382Test extends TestCase
{
    public function testPatchIsIdempotentAndRewritesCoalesce(): void
    {
        $root = dirname(__DIR__, 2);
        $tmp = sys_get_temp_dir().'/RouteCollector_36382_'.getmypid().'.php';
        $seed = <<<'PHP'
<?php
namespace Slim\Routing;
class RouteCollector
{
    public function __construct(
        $responseFactory,
        $callableResolver,
        $container = null,
        $defaultInvocationStrategy = null,
        $routeParser = null,
        $cacheFile = null
    ) {
        $this->responseFactory = $responseFactory;
        $this->callableResolver = $callableResolver;
        $this->container = $container;
        $this->defaultInvocationStrategy = $defaultInvocationStrategy ?? new RequestResponse();
        $this->routeParser = $routeParser ?? new RouteParser($this);

        if ($cacheFile) {
            $this->setCacheFile($cacheFile);
        }
    }
}
PHP;
        file_put_contents($tmp, $seed);
        $script = $root.'/script/composer/patch-slim-route-collector-36382.php';
        exec(escapeshellarg(PHP_BINARY).' '.escapeshellarg($script).' '.escapeshellarg($tmp).' 2>&1', $out, $rc);
        $this->assertSame(0, $rc, implode("\n", $out));
        $patched = file_get_contents($tmp);
        $this->assertNotFalse($patched);
        $this->assertStringContainsString('instanceof InvocationStrategyInterface', $patched);
        $this->assertStringContainsString('instanceof RouteParserInterface', $patched);
        $this->assertStringContainsString('AOT (#36382): typed nullable strategy/parser', $patched);
        exec(escapeshellarg(PHP_BINARY).' '.escapeshellarg($script).' '.escapeshellarg($tmp).' 2>&1', $out2, $rc2);
        $this->assertSame(0, $rc2, implode("\n", $out2));
        $this->assertStringContainsString('already patched', implode("\n", $out2));
        @unlink($tmp);
    }
}
