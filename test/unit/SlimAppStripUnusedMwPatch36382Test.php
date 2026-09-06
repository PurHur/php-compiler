<?php

declare(strict_types=1);

namespace PHPCompiler\Test\Unit;

use PHPUnit\Framework\TestCase;

/**
 * #36382 — strip unused App add*Middleware so AutoloadDiscovery does not pull Error*.
 */
final class SlimAppStripUnusedMwPatch36382Test extends TestCase
{
    public function testStripRemovesErrorMiddlewareNewAndIsIdempotent(): void
    {
        $root = dirname(__DIR__, 2);
        $tmp = sys_get_temp_dir().'/App_strip_mw_36382_'.getmypid().'.php';
        $seed = <<<'PHP'
<?php
namespace Slim;

use Psr\Log\LoggerInterface;
use Slim\Middleware\BodyParsingMiddleware;
use Slim\Middleware\ErrorMiddleware;
use Slim\Middleware\RoutingMiddleware;
use Psr\Http\Server\MiddlewareInterface;
use Psr\Http\Message\ServerRequestInterface;
use Psr\Http\Message\ResponseInterface;
use Psr\Http\Server\RequestHandlerInterface;
use Slim\Routing\RouteCollectorProxy;

class App extends RouteCollectorProxy implements RequestHandlerInterface
{
    public function addMiddleware(MiddlewareInterface $middleware): self
    {
        return $this;
    }

    /**
     * Add the Slim built-in routing middleware to the app middleware stack
     *
     * This method can be used to control middleware order and is not required for default routing operation.
     *
     * @return RoutingMiddleware
     */
    public function addRoutingMiddleware(): RoutingMiddleware
    {
        $routingMiddleware = new RoutingMiddleware(
            $this->getRouteResolver(),
            $this->getRouteCollector()->getRouteParser()
        );
        $this->add($routingMiddleware);
        return $routingMiddleware;
    }

    /**
     * Add the Slim built-in error middleware to the app middleware stack
     *
     * @param bool                 $displayErrorDetails
     * @param bool                 $logErrors
     * @param bool                 $logErrorDetails
     * @param LoggerInterface|null $logger
     *
     * @return ErrorMiddleware
     */
    public function addErrorMiddleware(
        bool $displayErrorDetails,
        bool $logErrors,
        bool $logErrorDetails,
        ?LoggerInterface $logger = null
    ): ErrorMiddleware {
        $errorMiddleware = new ErrorMiddleware(
            $this->getCallableResolver(),
            $this->getResponseFactory(),
            $displayErrorDetails,
            $logErrors,
            $logErrorDetails,
            $logger
        );
        $this->add($errorMiddleware);
        return $errorMiddleware;
    }

    /**
     * Add the Slim body parsing middleware to the app middleware stack
     *
     * @param callable[] $bodyParsers
     *
     * @return BodyParsingMiddleware
     */
    public function addBodyParsingMiddleware(array $bodyParsers = []): BodyParsingMiddleware
    {
        $bodyParsingMiddleware = new BodyParsingMiddleware($bodyParsers);
        $this->add($bodyParsingMiddleware);
        return $bodyParsingMiddleware;
    }

    public function handle(ServerRequestInterface $request): ResponseInterface
    {
        return $this->middlewareDispatcher->handle($request);
    }
}
PHP;
        file_put_contents($tmp, $seed);
        $script = $root.'/script/composer/patch-slim-app-strip-unused-mw-36382.php';
        exec(escapeshellarg(PHP_BINARY).' '.escapeshellarg($script).' '.escapeshellarg($tmp).' 2>&1', $out, $rc);
        $this->assertSame(0, $rc, implode("\n", $out));
        $patched = file_get_contents($tmp);
        $this->assertNotFalse($patched);
        $this->assertStringContainsString('AOT (#36382): strip unused add*Middleware', $patched);
        $this->assertStringNotContainsString('new ErrorMiddleware', $patched);
        $this->assertStringNotContainsString('new BodyParsingMiddleware', $patched);
        $this->assertStringNotContainsString('new RoutingMiddleware', $patched);
        $this->assertStringNotContainsString('use Slim\\Middleware\\ErrorMiddleware', $patched);
        exec(escapeshellarg(PHP_BINARY).' '.escapeshellarg($script).' '.escapeshellarg($tmp).' 2>&1', $out2, $rc2);
        $this->assertSame(0, $rc2, implode("\n", $out2));
        $this->assertStringContainsString('already stripped', implode("\n", $out2));
        @unlink($tmp);
    }
}
