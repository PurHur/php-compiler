<?php

declare(strict_types=1);

namespace PHPCompiler\Test\Unit;

use PHPUnit\Framework\TestCase;

/**
 * #36382 — strip unused AppFactory probe / createFromContainer for preset ResponseFactory hello.
 */
final class SlimAppFactoryStripProbePatch36382Test extends TestCase
{
    public function testStripRemovesProbeAndIsIdempotent(): void
    {
        $root = dirname(__DIR__, 2);
        $tmp = sys_get_temp_dir().'/AppFactory_strip_probe_36382_'.getmypid().'.php';
        $seed = <<<'PHP'
<?php
namespace Slim\Factory;

use Psr\Container\ContainerInterface;
use Psr\Http\Message\ResponseFactoryInterface;
use Psr\Http\Message\StreamFactoryInterface;
use RuntimeException;
use Slim\App;
use Slim\Factory\Psr17\Psr17Factory;
use Slim\Factory\Psr17\Psr17FactoryProvider;
use Slim\Factory\Psr17\SlimHttpPsr17Factory;
use Slim\Interfaces\CallableResolverInterface;
use Slim\Interfaces\MiddlewareDispatcherInterface;
use Slim\Interfaces\Psr17FactoryProviderInterface;
use Slim\Interfaces\RouteCollectorInterface;
use Slim\Interfaces\RouteResolverInterface;

class AppFactory
{
    protected static ?Psr17FactoryProviderInterface $psr17FactoryProvider = null;

    protected static ?ResponseFactoryInterface $responseFactory = null;

    protected static ?StreamFactoryInterface $streamFactory = null;

    protected static ?ContainerInterface $container = null;

    protected static ?CallableResolverInterface $callableResolver = null;

    protected static ?RouteCollectorInterface $routeCollector = null;

    protected static ?RouteResolverInterface $routeResolver = null;

    protected static ?MiddlewareDispatcherInterface $middlewareDispatcher = null;

    protected static bool $slimHttpDecoratorsAutomaticDetectionEnabled = true;

    public static function create(
        ?ResponseFactoryInterface $responseFactory = null,
        ?ContainerInterface $container = null,
        ?CallableResolverInterface $callableResolver = null,
        ?RouteCollectorInterface $routeCollector = null,
        ?RouteResolverInterface $routeResolver = null,
        ?MiddlewareDispatcherInterface $middlewareDispatcher = null
    ): App {
        // AOT (#36382): spill AppFactory::create args to temps before `new App` —
        // inline `determineResponseFactory()` / `?? static::$x` as NEW operands
        // mis-wires ARG_SEND (duplicate slots / EXEC_NORETURN) under php-cfg.
        static::$responseFactory = $responseFactory ?? static::$responseFactory;
        $resolvedResponseFactory = self::determineResponseFactory();
        $resolvedContainer = $container ?? static::$container;
        $resolvedCallableResolver = $callableResolver ?? static::$callableResolver;
        $resolvedRouteCollector = $routeCollector ?? static::$routeCollector;
        $resolvedRouteResolver = $routeResolver ?? static::$routeResolver;
        $resolvedMiddlewareDispatcher = $middlewareDispatcher ?? static::$middlewareDispatcher;
        return new App(
            $resolvedResponseFactory,
            $resolvedContainer,
            $resolvedCallableResolver,
            $resolvedRouteCollector,
            $resolvedRouteResolver,
            $resolvedMiddlewareDispatcher
        );
    }

    /**
     * @template TContainerInterface of (ContainerInterface)
     * @param TContainerInterface $container
     * @return App<TContainerInterface>
     */
    public static function createFromContainer(ContainerInterface $container): App
    {
        $responseFactory = $container->has(ResponseFactoryInterface::class)
        && (
            $responseFactoryFromContainer = $container->get(ResponseFactoryInterface::class)
        ) instanceof ResponseFactoryInterface
            ? $responseFactoryFromContainer
            : self::determineResponseFactory();

        $callableResolver = $container->has(CallableResolverInterface::class)
        && (
            $callableResolverFromContainer = $container->get(CallableResolverInterface::class)
        ) instanceof CallableResolverInterface
            ? $callableResolverFromContainer
            : null;

        $routeCollector = $container->has(RouteCollectorInterface::class)
        && (
            $routeCollectorFromContainer = $container->get(RouteCollectorInterface::class)
        ) instanceof RouteCollectorInterface
            ? $routeCollectorFromContainer
            : null;

        $routeResolver = $container->has(RouteResolverInterface::class)
        && (
            $routeResolverFromContainer = $container->get(RouteResolverInterface::class)
        ) instanceof RouteResolverInterface
            ? $routeResolverFromContainer
            : null;

        $middlewareDispatcher = $container->has(MiddlewareDispatcherInterface::class)
        && (
            $middlewareDispatcherFromContainer = $container->get(MiddlewareDispatcherInterface::class)
        ) instanceof MiddlewareDispatcherInterface
            ? $middlewareDispatcherFromContainer
            : null;

        return new App(
            $responseFactory,
            $container,
            $callableResolver,
            $routeCollector,
            $routeResolver,
            $middlewareDispatcher
        );
    }

    /**
     * @throws RuntimeException
     */
    public static function determineResponseFactory(): ResponseFactoryInterface
    {
        if (static::$responseFactory) {
            if (static::$streamFactory) {
                return static::attemptResponseFactoryDecoration(static::$responseFactory, static::$streamFactory);
            }
            return static::$responseFactory;
        }

        $psr17FactoryProvider = static::$psr17FactoryProvider ?? new Psr17FactoryProvider();

        /** @var Psr17Factory $psr17factory */
        foreach ($psr17FactoryProvider->getFactories() as $psr17factory) {
            if ($psr17factory::isResponseFactoryAvailable()) {
                $responseFactory = $psr17factory::getResponseFactory();

                if (static::$streamFactory || $psr17factory::isStreamFactoryAvailable()) {
                    $streamFactory = static::$streamFactory ?? $psr17factory::getStreamFactory();
                    return static::attemptResponseFactoryDecoration($responseFactory, $streamFactory);
                }

                return $responseFactory;
            }
        }

        throw new RuntimeException(
            "Could not detect any PSR-17 ResponseFactory implementations. " .
            "Please install a supported implementation in order to use `AppFactory::create()`. " .
            "See https://github.com/slimphp/Slim/blob/4.x/README.md for a list of supported implementations."
        );
    }

    protected static function attemptResponseFactoryDecoration(
        ResponseFactoryInterface $responseFactory,
        StreamFactoryInterface $streamFactory
    ): ResponseFactoryInterface {
        if (
            static::$slimHttpDecoratorsAutomaticDetectionEnabled
            && SlimHttpPsr17Factory::isResponseFactoryAvailable()
        ) {
            return SlimHttpPsr17Factory::createDecoratedResponseFactory($responseFactory, $streamFactory);
        }

        return $responseFactory;
    }

    public static function setPsr17FactoryProvider(Psr17FactoryProviderInterface $psr17FactoryProvider): void
    {
        static::$psr17FactoryProvider = $psr17FactoryProvider;
    }

    public static function setResponseFactory(ResponseFactoryInterface $responseFactory): void
    {
        static::$responseFactory = $responseFactory;
    }

    public static function setStreamFactory(StreamFactoryInterface $streamFactory): void
    {
        static::$streamFactory = $streamFactory;
    }

    public static function setSlimHttpDecoratorsAutomaticDetection(bool $enabled): void
    {
        static::$slimHttpDecoratorsAutomaticDetectionEnabled = $enabled;
    }
}
PHP;
        file_put_contents($tmp, $seed);
        $script = $root.'/script/composer/patch-slim-appfactory-strip-probe-36382.php';
        exec(escapeshellarg(PHP_BINARY).' '.escapeshellarg($script).' '.escapeshellarg($tmp).' 2>&1', $out, $rc);
        $this->assertSame(0, $rc, implode("\n", $out));
        $patched = file_get_contents($tmp);
        $this->assertNotFalse($patched);
        $this->assertStringContainsString('AOT (#36382): strip unused AppFactory probe', $patched);
        $this->assertStringContainsString('public static function create(', $patched);
        $this->assertStringNotContainsString('createFromContainer', $patched);
        $this->assertStringNotContainsString('new Psr17FactoryProvider', $patched);
        $this->assertStringNotContainsString('SlimHttpPsr17Factory', $patched);
        $this->assertStringNotContainsString('function setStreamFactory', $patched);
        exec(escapeshellarg(PHP_BINARY).' '.escapeshellarg($script).' '.escapeshellarg($tmp).' 2>&1', $out2, $rc2);
        $this->assertSame(0, $rc2, implode("\n", $out2));
        $this->assertStringContainsString('already stripped', implode("\n", $out2));
        @unlink($tmp);
    }
}
