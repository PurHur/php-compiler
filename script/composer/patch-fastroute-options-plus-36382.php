<?php

declare(strict_types=1);

/**
 * AOT (#36382): FastRoute `simpleDispatcher` / `cachedDispatcher` use `$options += [defaults]`.
 * Under AOT array `+=` does not fill missing keys, so `$options['routeCollector']` warns and
 * the dispatcher aborts (Slim `$app->handle()`). Expand defaults with isset() (Zend-equivalent).
 *
 * php-src: Zend/zend_binary_assign_op_helper / ZEND_ASSIGN_ADD on arrays (union).
 *
 * Usage: php script/composer/patch-fastroute-options-plus-36382.php path/to/functions.php
 */
$path = $argv[1] ?? '';
if ('' === $path || !is_file($path)) {
    fwrite(STDERR, "usage: {$argv[0]} functions.php\n");
    exit(1);
}
$text = file_get_contents($path);
if (false === $text) {
    fwrite(STDERR, "read failed: {$path}\n");
    exit(1);
}
if (str_contains($text, 'AOT (#36382): expand dispatcher options')) {
    echo "FastRoute functions.php already patched (#36382)\n";
    exit(0);
}

$oldSimple = <<<'PHP'
        $options += [
            'routeParser' => 'FastRoute\\RouteParser\\Std',
            'dataGenerator' => 'FastRoute\\DataGenerator\\GroupCountBased',
            'dispatcher' => 'FastRoute\\Dispatcher\\GroupCountBased',
            'routeCollector' => 'FastRoute\\RouteCollector',
        ];
PHP;
$newSimple = <<<'PHP'
        // AOT (#36382): expand dispatcher options — array += does not fill missing keys under AOT.
        $defaults = [
            'routeParser' => 'FastRoute\\RouteParser\\Std',
            'dataGenerator' => 'FastRoute\\DataGenerator\\GroupCountBased',
            'dispatcher' => 'FastRoute\\Dispatcher\\GroupCountBased',
            'routeCollector' => 'FastRoute\\RouteCollector',
        ];
        foreach ($defaults as $k => $v) {
            if (!isset($options[$k])) {
                $options[$k] = $v;
            }
        }
PHP;

$oldCached = <<<'PHP'
        $options += [
            'routeParser' => 'FastRoute\\RouteParser\\Std',
            'dataGenerator' => 'FastRoute\\DataGenerator\\GroupCountBased',
            'dispatcher' => 'FastRoute\\Dispatcher\\GroupCountBased',
            'routeCollector' => 'FastRoute\\RouteCollector',
            'cacheDisabled' => false,
        ];
PHP;
$newCached = <<<'PHP'
        // AOT (#36382): expand dispatcher options — array += does not fill missing keys under AOT.
        $defaults = [
            'routeParser' => 'FastRoute\\RouteParser\\Std',
            'dataGenerator' => 'FastRoute\\DataGenerator\\GroupCountBased',
            'dispatcher' => 'FastRoute\\Dispatcher\\GroupCountBased',
            'routeCollector' => 'FastRoute\\RouteCollector',
            'cacheDisabled' => false,
        ];
        foreach ($defaults as $k => $v) {
            if (!isset($options[$k])) {
                $options[$k] = $v;
            }
        }
PHP;

if (!str_contains($text, $oldSimple) || !str_contains($text, $oldCached)) {
    fwrite(STDERR, "FastRoute options += patterns not found\n");
    exit(1);
}
$text = str_replace($oldSimple, $newSimple, $text, $c1);
$text = str_replace($oldCached, $newCached, $text, $c2);
if (1 !== $c1 || 1 !== $c2) {
    fwrite(STDERR, "expected 1+1 replacements, got {$c1}+{$c2}\n");
    exit(1);
}
file_put_contents($path, $text);
echo "patched FastRoute functions.php for AOT (#36382)\n";
