<?php

declare(strict_types=1);

namespace PHPCompiler\Test\Unit;

use PHPUnit\Framework\TestCase;

/**
 * #36382 — strip unused App::run() so AutoloadDiscovery does not pull ServerRequest* / SlimHttp*.
 */
final class SlimAppStripRunPatch36382Test extends TestCase
{
    public function testStripRemovesRunAndIsIdempotent(): void
    {
        $root = dirname(__DIR__, 2);
        $tmp = sys_get_temp_dir().'/App_strip_run_36382_'.getmypid().'.php';
        $seed = <<<'PHP'
<?php
namespace Slim;

use Psr\Http\Message\ResponseInterface;
use Psr\Http\Message\ServerRequestInterface;
use Psr\Http\Server\RequestHandlerInterface;
use Slim\Factory\ServerRequestCreatorFactory;
use Slim\Routing\RouteCollectorProxy;

class App extends RouteCollectorProxy implements RequestHandlerInterface
{
    // AOT (#36382): strip unused add*Middleware — AutoloadDiscovery walks every
    // method CFG and would pull Error*/Logger/BodyParsing/RoutingMiddleware units
    // into the hello graph even when handle()/run() never call them.
    public function handle(ServerRequestInterface $request): ResponseInterface
    {
        return $this->middlewareDispatcher->handle($request);
    }

    /**
     * Run application
     *
     * This method traverses the application middleware stack and then sends the
     * resultant Response object to the HTTP client.
     *
     * @param ServerRequestInterface|null $request
     * @return void
     */
    public function run(?ServerRequestInterface $request = null): void
    {
        if (!$request) {
            $serverRequestCreator = ServerRequestCreatorFactory::create();
            $request = $serverRequestCreator->createServerRequestFromGlobals();
        }

        $response = $this->handle($request);
        $responseEmitter = new ResponseEmitter();
        $responseEmitter->emit($response);
    }
}
PHP;
        file_put_contents($tmp, $seed);
        $script = $root.'/script/composer/patch-slim-app-strip-run-36382.php';
        exec(escapeshellarg(PHP_BINARY).' '.escapeshellarg($script).' '.escapeshellarg($tmp).' 2>&1', $out, $rc);
        $this->assertSame(0, $rc, implode("\n", $out));
        $patched = file_get_contents($tmp);
        $this->assertNotFalse($patched);
        $this->assertStringContainsString('AOT (#36382): strip unused App::run()', $patched);
        $this->assertStringNotContainsString('function run(', $patched);
        $this->assertStringNotContainsString('use Slim\\Factory\\ServerRequestCreatorFactory', $patched);
        $this->assertStringNotContainsString('new ResponseEmitter', $patched);
        exec(escapeshellarg(PHP_BINARY).' '.escapeshellarg($script).' '.escapeshellarg($tmp).' 2>&1', $out2, $rc2);
        $this->assertSame(0, $rc2, implode("\n", $out2));
        $this->assertStringContainsString('already stripped', implode("\n", $out2));
        @unlink($tmp);
    }
}
