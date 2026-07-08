<?php

declare(strict_types=1);

if (!function_exists('array_all_key')) {
    echo "fail: array_all_key() not registered\n";
    exit(1);
}
if (!function_exists('array_any_key')) {
    echo "fail: array_any_key() not registered\n";
    exit(1);
}

$h = ['a' => 1, 'b' => '1'];

if (!array_all_key($h, fn ($k, $v) => $v == 1, false)) {
    echo "fail: loose bool callback should pass all elements\n";
    exit(1);
}

if (!array_all_key($h, fn ($k, $v) => $v == 1, true)) {
    echo "fail: strict bool callback should pass when predicate returns true\n";
    exit(1);
}

if (array_all_key($h, fn ($k, $v) => $v == 1 ? 1 : 0, true)) {
    echo "fail: strict truthy-int callback should fail (1 !== true)\n";
    exit(1);
}

if (!array_all_key($h, fn ($k, $v) => $v == 1 ? 1 : 0, false)) {
    echo "fail: loose truthy-int callback should pass\n";
    exit(1);
}

if (!array_any_key($h, fn ($k, $v) => $v == 1 ? 1 : 0, false)) {
    echo "fail: array_any_key loose truthy-int should match\n";
    exit(1);
}

if (array_any_key($h, fn ($k, $v) => $v == 1 ? 1 : 0, true)) {
    echo "fail: array_any_key strict truthy-int should not match\n";
    exit(1);
}

echo "ok\n";
