<?php

declare(strict_types=1);

/**
 * AOT (#36382): Slim AppFactory::createFromContainer + determineResponseFactory's
 * Psr17FactoryProvider probe / SlimHttp decoration are unused when the hello
 * fixture calls AppFactory::setResponseFactory($psr17) first. AutoloadDiscovery
 * still walks those method CFGs and pulls SlimPsr17 / HttpSoft / Laminas / Guzzle /
 * SlimHttpPsr17Factory into the reachable graph.
 *
 * Specialize for the preset-response-factory path and drop createFromContainer.
 *
 * php-src: n/a (fixture shrink for AutoloadDiscovery).
 *
 * Usage: php script/composer/patch-slim-appfactory-strip-probe-36382.php path/to/AppFactory.php
 */
$path = $argv[1] ?? '';
if ('' === $path || !is_file($path)) {
    fwrite(STDERR, "usage: {$argv[0]} AppFactory.php\n");
    exit(1);
}
$text = file_get_contents($path);
if (false === $text) {
    fwrite(STDERR, "read failed: {$path}\n");
    exit(1);
}
if (str_contains($text, 'AOT (#36382): strip unused AppFactory probe')) {
    echo "AppFactory.php probe paths already stripped (#36382)\n";
    exit(0);
}

foreach ([
    "use Psr\\Http\\Message\\StreamFactoryInterface;\n" => '',
    "use Slim\\Factory\\Psr17\\Psr17Factory;\n" => '',
    "use Slim\\Factory\\Psr17\\Psr17FactoryProvider;\n" => '',
    "use Slim\\Factory\\Psr17\\SlimHttpPsr17Factory;\n" => '',
    "use Slim\\Interfaces\\Psr17FactoryProviderInterface;\n" => '',
] as $old => $new) {
    if (!str_contains($text, $old)) {
        fwrite(STDERR, "expected import not found: " . trim($old) . "\n");
        exit(1);
    }
    $text = str_replace($old, $new, $text);
}

foreach ([
    "    protected static ?Psr17FactoryProviderInterface \$psr17FactoryProvider = null;\n\n" => '',
    "    protected static ?StreamFactoryInterface \$streamFactory = null;\n\n" => '',
    "    protected static bool \$slimHttpDecoratorsAutomaticDetectionEnabled = true;\n\n" => '',
] as $old => $new) {
    if (!str_contains($text, $old)) {
        fwrite(STDERR, "expected property block not found: " . substr($old, 0, 60) . "…\n");
        exit(1);
    }
    $text = str_replace($old, $new, $text);
}

// Exact createFromContainer body from Slim 4.15 (do not regex — create()'s docblock
// also contains `@return …App<TContainerInterface>…` and a greedy match ate create()).
$createFromContainer = <<<'PHP'
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

PHP;

if (!str_contains($text, $createFromContainer)) {
    fwrite(STDERR, "AppFactory::createFromContainer exact block not found\n");
    exit(1);
}
$text = str_replace($createFromContainer, '', $text);

$oldDetermine = <<<'PHP'
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

PHP;

$newDetermine = <<<'PHP'
    public static function determineResponseFactory(): ResponseFactoryInterface
    {
        // AOT (#36382): strip unused AppFactory probe — hello always
        // AppFactory::setResponseFactory($psr17) first; AutoloadDiscovery would
        // otherwise walk Psr17FactoryProvider / SlimHttp* into the graph.
        if (static::$responseFactory) {
            return static::$responseFactory;
        }

        throw new RuntimeException(
            "Could not detect any PSR-17 ResponseFactory implementations. " .
            "Please install a supported implementation in order to use `AppFactory::create()`. " .
            "See https://github.com/slimphp/Slim/blob/4.x/README.md for a list of supported implementations."
        );
    }

PHP;

if (!str_contains($text, $oldDetermine)) {
    fwrite(STDERR, "AppFactory::determineResponseFactory probe block not found\n");
    exit(1);
}
$text = str_replace($oldDetermine, $newDetermine, $text);

foreach ([
    <<<'PHP'
    public static function setStreamFactory(StreamFactoryInterface $streamFactory): void
    {
        static::$streamFactory = $streamFactory;
    }

PHP,
    <<<'PHP'
    public static function setSlimHttpDecoratorsAutomaticDetection(bool $enabled): void
    {
        static::$slimHttpDecoratorsAutomaticDetectionEnabled = $enabled;
    }

PHP,
] as $block) {
    if (!str_contains($text, $block)) {
        fwrite(STDERR, "expected setter block not found\n");
        exit(1);
    }
    $text = str_replace($block, '', $text);
}

if (!str_contains($text, 'public static function create(')) {
    fwrite(STDERR, "post-condition failed: AppFactory::create() missing after strip\n");
    exit(1);
}

file_put_contents($path, $text);
echo "stripped unused AppFactory probe paths for AOT (#36382)\n";
