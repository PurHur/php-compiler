<?php

declare(strict_types=1);

/**
 * AOT (#36382): Slim FastRouteDispatcher::dispatch / routingResults use typed
 * `: array` returns. Under IncludeHelper those returns silent-exit the caller
 * (DISP2 then no DISP3). Drop the return types (Zend still type-checks weakly
 * at the call sites via docblocks; behavior matches FOUND/NOT_FOUND tuples).
 *
 * php-src: Zend/zend_execute.c ZEND_RETURN for IS_ARRAY.
 *
 * Usage: php script/composer/patch-slim-fastroute-dispatcher-return-36382.php FastRouteDispatcher.php
 */
$path = $argv[1] ?? '';
if ('' === $path || !is_file($path)) {
    fwrite(STDERR, "usage: {$argv[0]} FastRouteDispatcher.php\n");
    exit(1);
}
$text = file_get_contents($path);
if (false === $text) {
    fwrite(STDERR, "read failed: {$path}\n");
    exit(1);
}
if (str_contains($text, 'AOT (#36382): untyped dispatch/routingResults returns')) {
    echo "FastRouteDispatcher.php already patched (#36382)\n";
    exit(0);
}

$orig = $text;
$text = str_replace(
    '    public function dispatch($httpMethod, $uri): array',
    "    // AOT (#36382): untyped dispatch/routingResults returns — typed `: array` silent-exits caller.\n"
    .'    public function dispatch($httpMethod, $uri)',
    $text,
    $c1
);
$text = str_replace(
    '    private function routingResults(string $httpMethod, string $uri): array',
    '    private function routingResults(string $httpMethod, string $uri)',
    $text,
    $c2
);
if (1 !== $c1 || 1 !== $c2) {
    fwrite(STDERR, "expected dispatch+routingResults rewrites, got {$c1}/{$c2}\n");
    exit(1);
}
if (false === file_put_contents($path, $text)) {
    fwrite(STDERR, "write failed: {$path}\n");
    exit(1);
}
echo "patched FastRouteDispatcher returns for AOT (#36382)\n";
