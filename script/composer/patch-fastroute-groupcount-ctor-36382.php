<?php

declare(strict_types=1);

/**
 * AOT (#36382): FastRoute GroupCountBased::__construct uses
 * `list($this->staticRouteMap, $this->variableRouteData) = $data`.
 * Under IncludeHelper that list-to-property assign leaves the map unusable and
 * the first routingResults isset aborts (SIGABRT). Split into `$data[0]` /
 * `$data[1]` assigns (Zend-equivalent).
 *
 * php-src: Zend/zend_vm_def.h ZEND_FETCH_LIST / ZEND_ASSIGN_OBJ.
 *
 * Usage: php script/composer/patch-fastroute-groupcount-ctor-36382.php GroupCountBased.php
 */
$path = $argv[1] ?? '';
if ('' === $path || !is_file($path)) {
    fwrite(STDERR, "usage: {$argv[0]} GroupCountBased.php\n");
    exit(1);
}
if ('GroupCountBased.php' !== basename($path)) {
    fwrite(STDERR, "expected GroupCountBased.php, got ".basename($path)."\n");
    exit(1);
}
$text = file_get_contents($path);
if (false === $text) {
    fwrite(STDERR, "read failed\n");
    exit(1);
}
if (str_contains($text, 'AOT (#36382): avoid list() into dispatcher props')) {
    echo "GroupCountBased.php already patched (#36382)\n";
    exit(0);
}
// Only patch Dispatcher\GroupCountBased (has staticRouteMap), not DataGenerator\
if (!str_contains($text, 'staticRouteMap')) {
    fwrite(STDERR, "not Dispatcher\\GroupCountBased (no staticRouteMap)\n");
    exit(1);
}
$old = <<<'PHP'
    public function __construct($data)
    {
        list($this->staticRouteMap, $this->variableRouteData) = $data;
    }
PHP;
$new = <<<'PHP'
    public function __construct($data)
    {
        // AOT (#36382): avoid list() into dispatcher props — IncludeHelper list-assign
        // leaves staticRouteMap unusable; routingResults isset then SIGABRT.
        $this->staticRouteMap = $data[0];
        $this->variableRouteData = $data[1];
    }
PHP;
if (!str_contains($text, $old)) {
    fwrite(STDERR, "GroupCountBased ctor pattern not found\n");
    exit(1);
}
file_put_contents($path, str_replace($old, $new, $text, $c));
if (1 !== $c) {
    fwrite(STDERR, "expected 1, got $c\n");
    exit(1);
}
echo "patched FastRoute Dispatcher GroupCountBased ctor for AOT (#36382)\n";
