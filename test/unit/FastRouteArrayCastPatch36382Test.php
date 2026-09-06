<?php

declare(strict_types=1);

namespace PHPCompiler\Test\Unit;

use PHPUnit\Framework\TestCase;

/**
 * #36382 — FastRoute addRoute must not use `(array)` cast under AOT (CAST_ARRAY stall).
 */
final class FastRouteArrayCastPatch36382Test extends TestCase
{
    public function testPatchRewritesArrayCastToIsArrayTernary(): void
    {
        $root = dirname(__DIR__, 2);
        $tmp = sys_get_temp_dir().'/fastroute_rc_cast_36382_'.getmypid().'.php';
        $seed = <<<'PHP'
<?php
namespace FastRoute;

class RouteCollector
{
    public function addRoute($httpMethod, $route, $handler)
    {
        $route = $this->currentGroupPrefix . $route;
        $routeDatas = $this->routeParser->parse($route);
        foreach ((array) $httpMethod as $method) {
            foreach ($routeDatas as $routeData) {
                $this->dataGenerator->addRoute($method, $routeData, $handler);
            }
        }
    }
}
PHP;
        file_put_contents($tmp, $seed);
        $script = $root.'/script/composer/patch-fastroute-array-cast-36382.php';
        exec(escapeshellarg(PHP_BINARY).' '.escapeshellarg($script).' '.escapeshellarg($tmp).' 2>&1', $out, $rc);
        $this->assertSame(0, $rc, implode("\n", $out));
        $patched = file_get_contents($tmp);
        $this->assertNotFalse($patched);
        $this->assertStringContainsString('AOT (#36382): avoid (array) cast in addRoute', $patched);
        $this->assertStringContainsString('$methods = is_array($httpMethod) ? $httpMethod : [$httpMethod];', $patched);
        $this->assertStringContainsString('foreach ($methods as $method)', $patched);
        $this->assertStringNotContainsString('foreach ((array) $httpMethod as $method)', $patched);

        exec(escapeshellarg(PHP_BINARY).' '.escapeshellarg($script).' '.escapeshellarg($tmp).' 2>&1', $out2, $rc2);
        $this->assertSame(0, $rc2, implode("\n", $out2));
        $this->assertStringContainsString('already patched', implode("\n", $out2));
        @unlink($tmp);
    }
}
