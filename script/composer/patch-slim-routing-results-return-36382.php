<?php

declare(strict_types=1);

/**
 * AOT (#36382): RoutingResults::getRouteArguments(): array / getAllowedMethods(): array
 * TypeError under IncludeHelper when FastRoute returns an untyped empty args list
 * ("Return value must be of type array, mixed returned"). Drop return types
 * (Zend-equivalent for callers that only foreach / pass the list).
 *
 * php-src: Zend/zend_execute.c ZEND_RETURN / ZEND_VERIFY_RETURN_TYPE.
 *
 * Usage: php script/composer/patch-slim-routing-results-return-36382.php RoutingResults.php
 */
$path = $argv[1] ?? '';
if ('' === $path || !is_file($path)) {
    fwrite(STDERR, "usage: {$argv[0]} RoutingResults.php\n");
    exit(1);
}
$text = file_get_contents($path);
if (false === $text) {
    fwrite(STDERR, "read failed\n");
    exit(1);
}
if (str_contains($text, 'AOT (#36382): untyped getRouteArguments/getAllowedMethods')) {
    echo "RoutingResults.php already patched (#36382)\n";
    exit(0);
}

$orig = $text;
$text = str_replace(
    '    public function getRouteArguments(bool $urlDecode = true): array',
    "    // AOT (#36382): untyped getRouteArguments/getAllowedMethods — typed `: array` TypeErrors on mixed empty args.\n"
    .'    public function getRouteArguments(bool $urlDecode = true)',
    $text,
    $c1
);
$text = str_replace(
    '    public function getAllowedMethods(): array',
    '    public function getAllowedMethods()',
    $text,
    $c2
);
if (1 !== $c1 || 1 !== $c2) {
    fwrite(STDERR, "expected getRouteArguments+getAllowedMethods rewrites, got {$c1}/{$c2}\n");
    exit(1);
}
if (false === file_put_contents($path, $text)) {
    fwrite(STDERR, "write failed\n");
    exit(1);
}
echo "patched RoutingResults returns for AOT (#36382)\n";
