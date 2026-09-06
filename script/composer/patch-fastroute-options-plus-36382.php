<?php

declare(strict_types=1);

/**
 * AOT (#36382): FastRoute `simpleDispatcher` / `cachedDispatcher` use `$options += [defaults]`.
 * Under AOT array `+=` does not fill missing keys. An isset-foreach mutate of the array
 * param also segfaults under AOT — rebuild with `??` instead (Zend-equivalent for missing keys).
 *
 * php-src: Zend/zend_binary_assign_op_helper / ZEND_ASSIGN_ADD on arrays (union);
 * Zend/zend_vm_def.h ZEND_COALESCE / ZEND_ISSET_ISEMPTY_DIM_OBJ.
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
if (str_contains($text, 'AOT (#36382): coalesce dispatcher options')) {
    echo "FastRoute functions.php already patched (#36382)\n";
    exit(0);
}

$newSimple = <<<'PHP'
        // AOT (#36382): coalesce dispatcher options — array += misses keys under AOT;
        // isset-foreach mutate of the array param segfaults (#36382).
        $options = [
            'routeParser' => $options['routeParser'] ?? 'FastRoute\\RouteParser\\Std',
            'dataGenerator' => $options['dataGenerator'] ?? 'FastRoute\\DataGenerator\\GroupCountBased',
            'dispatcher' => $options['dispatcher'] ?? 'FastRoute\\Dispatcher\\GroupCountBased',
            'routeCollector' => $options['routeCollector'] ?? 'FastRoute\\RouteCollector',
        ];
PHP;

$newCached = <<<'PHP'
        // AOT (#36382): coalesce dispatcher options — array += misses keys under AOT;
        // isset-foreach mutate of the array param segfaults (#36382).
        $options = [
            'routeParser' => $options['routeParser'] ?? 'FastRoute\\RouteParser\\Std',
            'dataGenerator' => $options['dataGenerator'] ?? 'FastRoute\\DataGenerator\\GroupCountBased',
            'dispatcher' => $options['dispatcher'] ?? 'FastRoute\\Dispatcher\\GroupCountBased',
            'routeCollector' => $options['routeCollector'] ?? 'FastRoute\\RouteCollector',
            'cacheDisabled' => $options['cacheDisabled'] ?? false,
        ];
PHP;

$oldSimplePlus = <<<'PHP'
        $options += [
            'routeParser' => 'FastRoute\\RouteParser\\Std',
            'dataGenerator' => 'FastRoute\\DataGenerator\\GroupCountBased',
            'dispatcher' => 'FastRoute\\Dispatcher\\GroupCountBased',
            'routeCollector' => 'FastRoute\\RouteCollector',
        ];
PHP;
$oldSimpleIsset = <<<'PHP'
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

$oldCachedPlus = <<<'PHP'
        $options += [
            'routeParser' => 'FastRoute\\RouteParser\\Std',
            'dataGenerator' => 'FastRoute\\DataGenerator\\GroupCountBased',
            'dispatcher' => 'FastRoute\\Dispatcher\\GroupCountBased',
            'routeCollector' => 'FastRoute\\RouteCollector',
            'cacheDisabled' => false,
        ];
PHP;
$oldCachedIsset = <<<'PHP'
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

$replacedSimple = false;
foreach ([$oldSimplePlus, $oldSimpleIsset] as $old) {
    if (str_contains($text, $old)) {
        $text = str_replace($old, $newSimple, $text, $c);
        if (1 !== $c) {
            fwrite(STDERR, "simpleDispatcher replace count {$c}\n");
            exit(1);
        }
        $replacedSimple = true;
        break;
    }
}
$replacedCached = false;
foreach ([$oldCachedPlus, $oldCachedIsset] as $old) {
    if (str_contains($text, $old)) {
        $text = str_replace($old, $newCached, $text, $c);
        if (1 !== $c) {
            fwrite(STDERR, "cachedDispatcher replace count {$c}\n");
            exit(1);
        }
        $replacedCached = true;
        break;
    }
}
if (!$replacedSimple || !$replacedCached) {
    fwrite(STDERR, "FastRoute options patterns not found (simple=".($replacedSimple?'y':'n')." cached=".($replacedCached?'y':'n').")\n");
    exit(1);
}
file_put_contents($path, $text);
echo "patched FastRoute functions.php for AOT (#36382)\n";
