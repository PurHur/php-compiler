<?php

declare(strict_types=1);

/**
 * AOT (#36382): FastRoute `simpleDispatcher` / `cachedDispatcher` use `$options += [defaults]`.
 * Under AOT array `+=` does not fill missing keys. An isset-foreach mutate of the array
 * param also segfaults under AOT — rebuild with `??` into a **fresh local** `$merged`
 * (assigning an assoc literal back onto the array param leaves string-key FETCH_DIM
 * reading a stale formal under AOT — #36382).
 *
 * Also: `cachedDispatcher` does `require $options['cacheFile']` (computed path). AOT refuses
 * non-literal include (#54) while compiling functions.php even when only simpleDispatcher is
 * called (Slim). Skip the cache-load arm so the rebuild path remains (Slim never uses cache).
 *
 * php-src: Zend/zend_binary_assign_op_helper / ZEND_ASSIGN_ADD on arrays (union);
 * Zend/zend_vm_def.h ZEND_COALESCE / ZEND_ISSET_ISEMPTY_DIM_OBJ / ZEND_ASSIGN;
 * Zend/zend_compile.c zend_compile_include (literal path).
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

$did = false;

$newSimple = <<<'PHP'
        // AOT (#36382): coalesce dispatcher options into $merged — array += misses keys;
        // isset-foreach mutates the array param (SEGV); assigning assoc back onto $options
        // leaves string-key FETCH_DIM stale under AOT (#36382).
        $merged = [
            'routeParser' => $options['routeParser'] ?? 'FastRoute\\RouteParser\\Std',
            'dataGenerator' => $options['dataGenerator'] ?? 'FastRoute\\DataGenerator\\GroupCountBased',
            'dispatcher' => $options['dispatcher'] ?? 'FastRoute\\Dispatcher\\GroupCountBased',
            'routeCollector' => $options['routeCollector'] ?? 'FastRoute\\RouteCollector',
        ];
PHP;

$newCached = <<<'PHP'
        // AOT (#36382): coalesce dispatcher options into $merged — array += misses keys;
        // isset-foreach mutates the array param (SEGV); assigning assoc back onto $options
        // leaves string-key FETCH_DIM stale under AOT (#36382).
        $merged = [
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
$oldSimpleCoalesceIntoOptions = <<<'PHP'
        // AOT (#36382): coalesce dispatcher options — array += misses keys under AOT;
        // isset-foreach mutate of the array param segfaults (#36382).
        $options = [
            'routeParser' => $options['routeParser'] ?? 'FastRoute\\RouteParser\\Std',
            'dataGenerator' => $options['dataGenerator'] ?? 'FastRoute\\DataGenerator\\GroupCountBased',
            'dispatcher' => $options['dispatcher'] ?? 'FastRoute\\Dispatcher\\GroupCountBased',
            'routeCollector' => $options['routeCollector'] ?? 'FastRoute\\RouteCollector',
        ];
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
$oldCachedCoalesceIntoOptions = <<<'PHP'
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

if (!str_contains($text, 'coalesce dispatcher options into $merged')) {
    $replacedSimple = false;
    foreach ([$oldSimplePlus, $oldSimpleIsset, $oldSimpleCoalesceIntoOptions] as $old) {
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
    foreach ([$oldCachedPlus, $oldCachedIsset, $oldCachedCoalesceIntoOptions] as $old) {
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
    $did = true;
}

$oldSimpleUse = <<<'PHP'
        /** @var RouteCollector $routeCollector */
        $routeCollector = new $options['routeCollector'](
            new $options['routeParser'], new $options['dataGenerator']
        );
        $routeDefinitionCallback($routeCollector);

        return new $options['dispatcher']($routeCollector->getData());
PHP;
$newSimpleUse = <<<'PHP'
        /** @var RouteCollector $routeCollector */
        $routeCollector = new $merged['routeCollector'](
            new $merged['routeParser'], new $merged['dataGenerator']
        );
        $routeDefinitionCallback($routeCollector);

        return new $merged['dispatcher']($routeCollector->getData());
PHP;

if (str_contains($text, $oldSimpleUse)) {
    $text = str_replace($oldSimpleUse, $newSimpleUse, $text, $c);
    if (1 !== $c) {
        fwrite(STDERR, "simpleDispatcher use replace count {$c}\n");
        exit(1);
    }
    $did = true;
}

$oldCachedUseBeforeRequire = <<<'PHP'
        if (!isset($options['cacheFile'])) {
            throw new \LogicException('Must specify "cacheFile" option');
        }
PHP;
$newCachedUseBeforeRequire = <<<'PHP'
        if (!isset($merged['cacheFile'])) {
            throw new \LogicException('Must specify "cacheFile" option');
        }
PHP;
if (str_contains($text, $oldCachedUseBeforeRequire)) {
    $text = str_replace($oldCachedUseBeforeRequire, $newCachedUseBeforeRequire, $text, $c);
    if (1 !== $c) {
        fwrite(STDERR, "cachedDispatcher cacheFile isset replace count {$c}\n");
        exit(1);
    }
    $did = true;
}

$oldCacheRequire = <<<'PHP'
        if (!$options['cacheDisabled'] && file_exists($options['cacheFile'])) {
            $dispatchData = require $options['cacheFile'];
            if (!is_array($dispatchData)) {
                throw new \RuntimeException('Invalid cache file "' . $options['cacheFile'] . '"');
            }
            return new $options['dispatcher']($dispatchData);
        }

        $routeCollector = new $options['routeCollector'](
            new $options['routeParser'], new $options['dataGenerator']
        );
        $routeDefinitionCallback($routeCollector);

        /** @var RouteCollector $routeCollector */
        $dispatchData = $routeCollector->getData();
        if (!$options['cacheDisabled']) {
            file_put_contents(
                $options['cacheFile'],
                '<?php return ' . var_export($dispatchData, true) . ';'
            );
        }

        return new $options['dispatcher']($dispatchData);
PHP;

$newCacheRequire = <<<'PHP'
        // AOT (#36382): skip dynamic require $cacheFile (non-literal include #54).
        // Slim uses simpleDispatcher only; cachedDispatcher always rebuilds under AOT.
        if (false && !$merged['cacheDisabled'] && file_exists($merged['cacheFile'])) {
            throw new \LogicException('FastRoute cache load is disabled under AOT (#36382)');
        }

        $routeCollector = new $merged['routeCollector'](
            new $merged['routeParser'], new $merged['dataGenerator']
        );
        $routeDefinitionCallback($routeCollector);

        /** @var RouteCollector $routeCollector */
        $dispatchData = $routeCollector->getData();
        if (!$merged['cacheDisabled']) {
            file_put_contents(
                $merged['cacheFile'],
                '<?php return ' . var_export($dispatchData, true) . ';'
            );
        }

        return new $merged['dispatcher']($dispatchData);
PHP;

$oldCacheRequireAlreadySkipped = <<<'PHP'
        // AOT (#36382): skip dynamic require $cacheFile (non-literal include #54).
        // Slim uses simpleDispatcher only; cachedDispatcher always rebuilds under AOT.
        if (false && !$options['cacheDisabled'] && file_exists($options['cacheFile'])) {
            throw new \LogicException('FastRoute cache load is disabled under AOT (#36382)');
        }

        $routeCollector = new $options['routeCollector'](
            new $options['routeParser'], new $options['dataGenerator']
        );
        $routeDefinitionCallback($routeCollector);

        /** @var RouteCollector $routeCollector */
        $dispatchData = $routeCollector->getData();
        if (!$options['cacheDisabled']) {
            file_put_contents(
                $options['cacheFile'],
                '<?php return ' . var_export($dispatchData, true) . ';'
            );
        }

        return new $options['dispatcher']($dispatchData);
PHP;

$oldCacheRequireOnly = <<<'PHP'
        if (!$options['cacheDisabled'] && file_exists($options['cacheFile'])) {
            $dispatchData = require $options['cacheFile'];
            if (!is_array($dispatchData)) {
                throw new \RuntimeException('Invalid cache file "' . $options['cacheFile'] . '"');
            }
            return new $options['dispatcher']($dispatchData);
        }
PHP;

$newCacheRequireOnly = <<<'PHP'
        // AOT (#36382): skip dynamic require $cacheFile (non-literal include #54).
        // Slim uses simpleDispatcher only; cachedDispatcher always rebuilds under AOT.
        if (false && !$merged['cacheDisabled'] && file_exists($merged['cacheFile'])) {
            throw new \LogicException('FastRoute cache load is disabled under AOT (#36382)');
        }
PHP;

$oldCacheRequireOnlyAlreadySkipped = <<<'PHP'
        // AOT (#36382): skip dynamic require $cacheFile (non-literal include #54).
        // Slim uses simpleDispatcher only; cachedDispatcher always rebuilds under AOT.
        if (false && !$options['cacheDisabled'] && file_exists($options['cacheFile'])) {
            throw new \LogicException('FastRoute cache load is disabled under AOT (#36382)');
        }
PHP;

if (!str_contains($text, 'if (false && !$merged[\'cacheDisabled\']')) {
    if (str_contains($text, $oldCacheRequire)) {
        $text = str_replace($oldCacheRequire, $newCacheRequire, $text, $c);
        if (1 !== $c) {
            fwrite(STDERR, "cachedDispatcher require replace count {$c}\n");
            exit(1);
        }
        $did = true;
    } elseif (str_contains($text, $oldCacheRequireAlreadySkipped)) {
        $text = str_replace($oldCacheRequireAlreadySkipped, $newCacheRequire, $text, $c);
        if (1 !== $c) {
            fwrite(STDERR, "cachedDispatcher skipped-require upgrade count {$c}\n");
            exit(1);
        }
        $did = true;
    } elseif (str_contains($text, $oldCacheRequireOnly)) {
        $text = str_replace($oldCacheRequireOnly, $newCacheRequireOnly, $text, $c);
        if (1 !== $c) {
            fwrite(STDERR, "cachedDispatcher require-only replace count {$c}\n");
            exit(1);
        }
        $did = true;
    } elseif (str_contains($text, $oldCacheRequireOnlyAlreadySkipped)) {
        $text = str_replace($oldCacheRequireOnlyAlreadySkipped, $newCacheRequireOnly, $text, $c);
        if (1 !== $c) {
            fwrite(STDERR, "cachedDispatcher require-only upgrade count {$c}\n");
            exit(1);
        }
        $did = true;
    }
}

if (!$did) {
    echo "FastRoute functions.php already patched (#36382)\n";
    exit(0);
}

file_put_contents($path, $text);
echo "patched FastRoute functions.php for AOT (#36382)\n";
