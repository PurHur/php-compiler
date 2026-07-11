<?php

declare(strict_types=1);

/**
 * Maintainer repro: get_declared_classes(exclude_deprecated: true) on forward profile (#4711).
 *
 * php-src: ext/standard/basic_functions.c — PHP_FUNCTION(get_declared_classes)
 */

#[\Deprecated]
class DeprecatedC {}

class ActiveC {}

$all = get_declared_classes();
if (!in_array(ActiveC::class, $all, true)) {
    echo "fail: ActiveC missing from unfiltered list\n";
    exit(1);
}
if (!in_array(DeprecatedC::class, $all, true)) {
    echo "fail: DeprecatedC missing from unfiltered list\n";
    exit(1);
}

try {
    $filtered = get_declared_classes(exclude_deprecated: true);
} catch (Throwable $e) {
    echo 'fail: ', get_class($e), ': ', $e->getMessage(), "\n";
    exit(1);
}

if (!in_array(ActiveC::class, $filtered, true)) {
    echo "fail: ActiveC missing from filtered list\n";
    exit(1);
}
if (in_array(DeprecatedC::class, $filtered, true)) {
    echo "fail: DeprecatedC still listed when exclude_deprecated=true\n";
    exit(1);
}

echo "ok\n";
