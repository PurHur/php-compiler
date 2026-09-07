<?php

declare(strict_types=1);

namespace PHPCompiler\Test\Unit;

use PHPUnit\Framework\TestCase;

/**
 * #36382 — Slim Route patch: Closure routes invoke directly; RequestResponse gets callRouteCallable.
 */
final class SlimRouteStrategyInvokePatch36382Test extends TestCase
{
    public function testPatchIsIdempotentAndRewritesHandle(): void
    {
        $root = dirname(__DIR__, 2);
        $tmp = sys_get_temp_dir().'/slim_rr_36382_'.getmypid();
        mkdir($tmp);
        $route = $tmp.'/Route.php';
        $rr = $tmp.'/RequestResponse.php';
        file_put_contents($rr, <<<'PHP'
<?php
namespace Slim\Handlers\Strategies;
class RequestResponse
{
    public function __invoke(
        callable $callable,
        ServerRequestInterface $request,
        ResponseInterface $response,
        array $routeArguments
    ): ResponseInterface {
        foreach ($routeArguments as $k => $v) {
            $request = $request->withAttribute($k, $v);
        }

        /** @var ResponseInterface */
        return $callable($request, $response, $routeArguments);
    }
}
PHP);
        file_put_contents($route, <<<'PHP'
<?php
namespace Slim\Routing;
use Slim\Interfaces\AdvancedCallableResolverInterface;
use Psr\Http\Server\RequestHandlerInterface;
use Slim\Interfaces\RequestHandlerInvocationStrategyInterface;
use Slim\Handlers\Strategies\RequestHandler;
use Psr\Http\Message\ServerRequestInterface;
use Psr\Http\Message\ResponseInterface;
class Route
{
    public function handle(ServerRequestInterface $request): ResponseInterface
    {
        if ($this->callableResolver instanceof AdvancedCallableResolverInterface) {
            $callable = $this->callableResolver->resolveRoute($this->callable);
        } else {
            $callable = $this->callableResolver->resolve($this->callable);
        }
        $strategy = $this->invocationStrategy;

        $strategyImplements = class_implements($strategy);

        if (
            is_array($callable)
            && $callable[0] instanceof RequestHandlerInterface
            && !in_array(RequestHandlerInvocationStrategyInterface::class, $strategyImplements)
        ) {
            $strategy = new RequestHandler();
        }

        $response = $this->responseFactory->createResponse();
        return $strategy($callable, $request, $response, $this->arguments);
    }
}
PHP);
        $script = $root.'/script/composer/patch-slim-route-strategy-invoke-36382.php';
        exec(escapeshellarg(PHP_BINARY).' '.escapeshellarg($script).' '.escapeshellarg($route).' '.escapeshellarg($rr).' 2>&1', $out, $rc);
        $this->assertSame(0, $rc, implode("\n", $out));
        $rrText = file_get_contents($rr);
        $routeText = file_get_contents($route);
        $this->assertNotFalse($rrText);
        $this->assertNotFalse($routeText);
        $this->assertStringContainsString('callRouteCallable', $rrText);
        $this->assertStringContainsString('callable instanceof \Closure', $routeText);
        $this->assertStringContainsString('$cb = $this->callable', $routeText);
        $this->assertStringContainsString('return $cb($request, $response, $this->arguments)', $routeText);
        $this->assertStringNotContainsString('return $strategy($callable', $routeText);
        exec(escapeshellarg(PHP_BINARY).' '.escapeshellarg($script).' '.escapeshellarg($route).' '.escapeshellarg($rr).' 2>&1', $out2, $rc2);
        $this->assertSame(0, $rc2, implode("\n", $out2));
        $this->assertStringContainsString('already patched', implode("\n", $out2));
        @unlink($route);
        @unlink($rr);
        @rmdir($tmp);
    }
}
