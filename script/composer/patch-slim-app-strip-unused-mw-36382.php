<?php

declare(strict_types=1);

/**
 * AOT (#36382): Slim App.php keeps addErrorMiddleware / addBodyParsingMiddleware /
 * addRoutingMiddleware even when the hello fixture never calls them. AutoloadDiscovery
 * walks every method CFG, so `new ErrorMiddleware` / BodyParsing / RoutingMiddleware
 * pull ~20 Error* + Logger units into the reachable Composer graph and inflate Slim
 * AOT from minutes toward half-hour compiles on 8–10g hosts.
 *
 * Hello / `$app->handle` / `$app->run()` only need RouteRunner (default routing) —
 * RoutingMiddleware is optional for early route context. Strip the unused add*
 * methods + their imports so ProjectGraph stays on the handle path.
 *
 * php-src: n/a (fixture shrink for AutoloadDiscovery; Zend behaviour unchanged for
 * callers that never invoke the stripped methods).
 *
 * Usage: php script/composer/patch-slim-app-strip-unused-mw-36382.php path/to/App.php
 */
$path = $argv[1] ?? '';
if ('' === $path || !is_file($path)) {
    fwrite(STDERR, "usage: {$argv[0]} App.php\n");
    exit(1);
}
$text = file_get_contents($path);
if (false === $text) {
    fwrite(STDERR, "read failed: {$path}\n");
    exit(1);
}
if (str_contains($text, 'AOT (#36382): strip unused add*Middleware')) {
    echo "App.php unused middleware already stripped (#36382)\n";
    exit(0);
}

$removals = [
    "use Psr\\Log\\LoggerInterface;\n" => '',
    "use Slim\\Middleware\\BodyParsingMiddleware;\n" => '',
    "use Slim\\Middleware\\ErrorMiddleware;\n" => '',
    "use Slim\\Middleware\\RoutingMiddleware;\n" => '',
];
foreach ($removals as $old => $new) {
    if (!str_contains($text, $old)) {
        fwrite(STDERR, "expected import not found: " . trim($old) . "\n");
        exit(1);
    }
    $text = str_replace($old, $new, $text);
}

$methods = [
    <<<'PHP'
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

PHP,
    <<<'PHP'
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

PHP,
    <<<'PHP'
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

PHP,
];

foreach ($methods as $i => $block) {
    if (!str_contains($text, $block)) {
        fwrite(STDERR, "add*Middleware method block #{$i} not found\n");
        exit(1);
    }
    $text = str_replace($block, '', $text);
}

$needle = "class App extends RouteCollectorProxy implements RequestHandlerInterface\n{\n";
$insert = $needle
    . "    // AOT (#36382): strip unused add*Middleware — AutoloadDiscovery walks every\n"
    . "    // method CFG and would pull Error*/Logger/BodyParsing/RoutingMiddleware units\n"
    . "    // into the hello graph even when handle()/run() never call them.\n";
if (!str_contains($text, $needle)) {
    fwrite(STDERR, "App class open brace not found\n");
    exit(1);
}
$text = str_replace($needle, $insert, $text);

file_put_contents($path, $text);
echo "stripped unused App add*Middleware for AOT (#36382)\n";
