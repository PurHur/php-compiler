<?php

declare(strict_types=1);

function phpc_aot_probe_cb($key, $value): bool
{
    return is_int($value) || '1' === $value;
}

$h = ['a' => 1, 'b' => '1'];

if (!call_user_func('phpc_aot_probe_cb', 'a', 1)) {
    echo "fail: call_user_func\n";
    exit(1);
}

if (!array_all_key($h, 'phpc_aot_probe_cb', false)) {
    echo "fail: array_all_key\n";
    exit(1);
}

if (!array_any_key($h, 'phpc_aot_probe_cb', false)) {
    echo "fail: array_any_key\n";
    exit(1);
}

echo "ok\n";
