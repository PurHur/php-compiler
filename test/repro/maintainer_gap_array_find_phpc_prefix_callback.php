<?php

declare(strict_types=1);

function phpc_aot_probe_cb($k, $v)
{
    return 'a' === $k && 1 === $v;
}

$h = ['a' => 1, 'b' => 2];

echo call_user_func('phpc_aot_probe_cb', 'a', 1) ? 'cuf:T' : 'cuf:F';
echo "\n";
echo function_exists('phpc_aot_probe_cb') ? 'fe:T' : 'fe:F';
echo "\n";
echo array_all_key($h, 'phpc_aot_probe_cb', false) ? 'all_key:T' : 'all_key:F';
echo "\n";
echo array_any_key($h, 'phpc_aot_probe_cb', false) ? 'any_key:T' : 'any_key:F';
echo "\n";
echo array_all($h, 'phpc_aot_probe_cb', false) ? 'all:T' : 'all:F';
echo "\n";
echo array_any($h, 'phpc_aot_probe_cb', false) ? 'any:T' : 'any:F';
echo "\n";
