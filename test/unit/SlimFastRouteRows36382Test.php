<?php

declare(strict_types=1);

namespace PHPCompiler\test\unit;

use PHPUnit\Framework\TestCase;

/**
 * #36382 — FastRoute rows captured at RouteCollector::map() for AOT Dispatcher.
 *
 * @group aot
 */
final class SlimFastRouteRows36382Test extends TestCase
{
    public function testRouteCollectorMapCaptureAndExport(): void
    {
        $repo = dirname(__DIR__, 2);
        $patch = $repo.'/script/composer/patch-slim-fastroute-rows-36382.php';
        $dir = sys_get_temp_dir().'/phpc_rc_36382_'.bin2hex(random_bytes(4));
        mkdir($dir);
        $tmp = $dir.'/RouteCollector.php';
        file_put_contents($tmp, <<<'PHP'
<?php
namespace Slim\Routing;
class RouteCollector {
    protected array $routes = [];
    protected array $routesByName = [];
    protected $routeCounter = 0;
    public function map(array $methods, string $pattern, $handler): RouteInterface
    {
        $route = $this->createRoute($methods, $pattern, $handler);
        $this->routes[$route->getIdentifier()] = $route;

        $routeName = $route->getName();
        if ($routeName !== null && !isset($this->routesByName[$routeName])) {
            $this->routesByName[$routeName] = $route;
        }

        $this->routeCounter++;

        return $route;
    }
    public function getRoutes(): array
    {
        return $this->routes;
    }
}
PHP);
        exec('php '.escapeshellarg($patch).' '.escapeshellarg($tmp).' 2>&1', $out, $ec);
        $this->assertSame(0, $ec, implode("\n", $out));
        $text = (string) file_get_contents($tmp);
        $this->assertStringContainsString('fastRouteRows', $text);
        $this->assertStringContainsString('exportFastRouteRows', $text);
        $this->assertStringContainsString('capture FastRoute row from map()', $text);
        $this->assertStringContainsString('function exportFastRouteRows()', $text);
        exec('php '.escapeshellarg($patch).' '.escapeshellarg($tmp).' 2>&1', $out2, $ec2);
        $this->assertSame(0, $ec2);
        $this->assertStringContainsString('already patched', implode("\n", $out2));
        @unlink($tmp);
        @rmdir($dir);
    }

    public function testDispatcherInlineUsesExportRows(): void
    {
        $repo = dirname(__DIR__, 2);
        $patch = $repo.'/script/composer/patch-slim-dispatcher-closure-36382.php';
        $tmp = sys_get_temp_dir().'/phpc_disp_36382_'.bin2hex(random_bytes(4)).'.php';
        file_put_contents($tmp, <<<'PHP'
<?php
namespace Slim\Routing;
use FastRoute\DataGenerator\GroupCountBased;
use FastRoute\RouteCollector as FastRouteCollector;
use FastRoute\RouteParser\Std;
class Dispatcher {
    private $routeCollector;
    private $dispatcher = null;
    protected function createDispatcher() {
        if ($this->dispatcher) {
            return $this->dispatcher;
        }
        $routeDefinitionCallback = function (FastRouteCollector $r): void {
            $basePath = $this->routeCollector->getBasePath();

            foreach ($this->routeCollector->getRoutes() as $route) {
                $r->addRoute($route->getMethods(), $basePath . $route->getPattern(), $route->getIdentifier());
            }
        };

        $cacheFile = $this->routeCollector->getCacheFile();
        if ($cacheFile) {
            /** @var FastRouteDispatcher $dispatcher */
            $dispatcher = \FastRoute\cachedDispatcher($routeDefinitionCallback, [
                'dataGenerator' => GroupCountBased::class,
                'dispatcher' => FastRouteDispatcher::class,
                'routeParser' => new Std(),
                'cacheFile' => $cacheFile,
            ]);
        } else {
            /** @var FastRouteDispatcher $dispatcher */
            $dispatcher = \FastRoute\simpleDispatcher($routeDefinitionCallback, [
                'dataGenerator' => GroupCountBased::class,
                'dispatcher' => FastRouteDispatcher::class,
                'routeParser' => new Std(),
            ]);
        }

        $this->dispatcher = $dispatcher;
        return $this->dispatcher;
    }
}
PHP);
        exec('php '.escapeshellarg($patch).' '.escapeshellarg($tmp).' 2>&1', $out, $ec);
        $this->assertSame(0, $ec, implode("\n", $out));
        $staticPatch = $repo.'/script/composer/patch-slim-dispatcher-static-map-36382.php';
        exec('php '.escapeshellarg($staticPatch).' '.escapeshellarg($tmp).' 2>&1', $out2, $ec2);
        $this->assertSame(0, $ec2, implode("\n", $out2));
        $text = (string) file_get_contents($tmp);
        $this->assertStringContainsString('static map from exportFastRouteRows', $text);
        $this->assertStringContainsString('exportFastRouteRows()', $text);
        $this->assertStringContainsString('new FastRouteDispatcher([$static, []])', $text);
        $this->assertStringNotContainsString('$frCollector->addRoute', $text);
        @unlink($tmp);
    }

    public function testSetupHooksPatches(): void
    {
        $repo = dirname(__DIR__, 2);
        $setup = (string) file_get_contents($repo.'/script/composer/setup-slim-hello-36382.sh');
        $this->assertStringContainsString('patch-slim-fastroute-rows-36382.php', $setup);
        $this->assertStringContainsString('patch-slim-dispatcher-closure-36382.php', $setup);
        $this->assertStringContainsString('patch-slim-dispatcher-static-map-36382.php', $setup);
        $this->assertStringContainsString('patch-slim-fastroute-dispatcher-return-36382.php', $setup);
        $this->assertStringContainsString('patch-nyholm-uri-construct-36382.php', $setup);
        $this->assertStringContainsString('patch-fastroute-groupcount-ctor-36382.php', $setup);
        $this->assertStringContainsString('patch-slim-fastroute-dispatcher-isset-36382.php', $setup);
        $this->assertStringContainsString('patch-slim-routing-results-return-36382.php', $setup);
        $this->assertStringNotContainsString('patch-slim-fastroute-rows-36382.php" "$ROUTE"', $setup);
    }
}
