<?php
declare(strict_types=1);
/**
 * AOT (#36382): nested isset($staticRouteMap[$method][$uri]) emits
 * Warning: Undefined array key "GET" under IncludeHelper when the method key is
 * missing (Zend isset is quiet). Split into two isset checks.
 *
 * php-src: Zend/zend_vm_def.h ZEND_ISSET_ISEMPTY_DIM — nested isset is quiet.
 */
$path = $argv[1] ?? '';
if ($path === '' || !is_file($path)) {
    fwrite(STDERR, "usage: {$argv[0]} FastRouteDispatcher.php\n");
    exit(1);
}
$text = file_get_contents($path);
if (str_contains($text, 'AOT (#36382): nested isset')) {
    echo "FastRouteDispatcher isset already patched (#36382)\n";
    exit(0);
}
$old = <<<'OLD'
    private function routingResults(string $httpMethod, string $uri)
    {
        if (isset($this->staticRouteMap[$httpMethod][$uri])) {
            /** @var string $routeIdentifier */
            $routeIdentifier = $this->staticRouteMap[$httpMethod][$uri];
            return [self::FOUND, $routeIdentifier, []];
        }

        if (isset($this->variableRouteData[$httpMethod])) {
OLD;
$new = <<<'NEW'
    private function routingResults(string $httpMethod, string $uri)
    {
        // AOT (#36382): nested isset($map[$method][$uri]) warns Undefined array key
        // on missing $method under IncludeHelper; split isset (Zend isset is quiet).
        if (isset($this->staticRouteMap[$httpMethod]) && isset($this->staticRouteMap[$httpMethod][$uri])) {
            /** @var string $routeIdentifier */
            $routeIdentifier = $this->staticRouteMap[$httpMethod][$uri];
            return [self::FOUND, $routeIdentifier, []];
        }

        if (isset($this->variableRouteData[$httpMethod])) {
NEW;
if (!str_contains($text, $old)) {
    // maybe already untyped from other patch - try without return type already applied
    fwrite(STDERR, "routingResults isset pattern not found\n");
    exit(1);
}
file_put_contents($path, str_replace($old, $new, $text, $c));
if ($c !== 1) {
    fwrite(STDERR, "expected 1, got $c\n");
    exit(1);
}
echo "patched FastRouteDispatcher nested isset for AOT (#36382)\n";
