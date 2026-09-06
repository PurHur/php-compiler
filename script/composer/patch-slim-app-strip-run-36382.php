<?php

declare(strict_types=1);

/**
 * AOT (#36382): Slim App::run() always constructs ServerRequestCreatorFactory +
 * ResponseEmitter. AutoloadDiscovery walks every method CFG, so a hello fixture that
 * only needs `$app->handle($request)` still pulls the full ServerRequest creator
 * stack (Psr17FactoryProvider backends, SlimHttp* decorators) into the reachable
 * graph and inflates full AOT toward multi-10-min NestedJIT on 8–10g hosts.
 *
 * The hello entry emits via handle() + CGI headers/body (see setup-slim-hello-36382.sh).
 * Strip run() + its ServerRequestCreatorFactory import so ProjectGraph stays on the
 * handle path (peer of patch-slim-app-strip-unused-mw-36382.php).
 *
 * php-src: n/a (fixture shrink for AutoloadDiscovery).
 *
 * Usage: php script/composer/patch-slim-app-strip-run-36382.php path/to/App.php
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
if (str_contains($text, 'AOT (#36382): strip unused App::run()')) {
    echo "App.php unused run() already stripped (#36382)\n";
    exit(0);
}

$import = "use Slim\\Factory\\ServerRequestCreatorFactory;\n";
if (!str_contains($text, $import)) {
    fwrite(STDERR, "expected ServerRequestCreatorFactory import not found\n");
    exit(1);
}
$text = str_replace($import, '', $text);

$runBlock = <<<'PHP'
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

PHP;

if (!str_contains($text, $runBlock)) {
    fwrite(STDERR, "App::run() method block not found\n");
    exit(1);
}
$text = str_replace($runBlock, '', $text);

$anchor = "    // into the hello graph even when handle()/run() never call them.\n";
$note = $anchor
    . "    // AOT (#36382): strip unused App::run() — AutoloadDiscovery walks every\n"
    . "    // method CFG and would pull ServerRequestCreatorFactory / ResponseEmitter /\n"
    . "    // SlimHttp* units into the hello graph even when entry uses handle()+emit.\n";
if (str_contains($text, $anchor)) {
    $text = str_replace($anchor, $note, $text);
} else {
    $needle = "class App extends RouteCollectorProxy implements RequestHandlerInterface\n{\n";
    $insert = $needle
        . "    // AOT (#36382): strip unused App::run() — AutoloadDiscovery walks every\n"
        . "    // method CFG and would pull ServerRequestCreatorFactory / ResponseEmitter /\n"
        . "    // SlimHttp* units into the hello graph even when entry uses handle()+emit.\n";
    if (!str_contains($text, $needle)) {
        fwrite(STDERR, "App class open brace not found\n");
        exit(1);
    }
    $text = str_replace($needle, $insert, $text);
}

file_put_contents($path, $text);
echo "stripped unused App::run() for AOT (#36382)\n";
