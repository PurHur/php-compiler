<?php

declare(strict_types=1);

/** Issue #13046 — FILTER_* defined() + get_defined_constants(true)['filter']. */

$filter = get_defined_constants(true)['filter'] ?? [];

$checks = [
    ['FILTER_DEFAULT', 516],
    ['FILTER_FLAG_NONE', 0],
    ['FILTER_VALIDATE_INT', 257],
];

foreach ($checks as [$name, $expected]) {
    $defined = defined($name);
    $value = $defined ? constant($name) : null;
    $inBucket = isset($filter[$name]) && $filter[$name] === $expected;
    echo $name,
        ' defined=',
        $defined ? 'true' : 'false',
        ' value=',
        var_export($value, true),
        ' in_filter_bucket=',
        $inBucket ? 'true' : 'false',
        PHP_EOL;
    if (!$defined || $value !== $expected || !$inBucket) {
        exit(1);
    }
}

echo 'ok', PHP_EOL;
