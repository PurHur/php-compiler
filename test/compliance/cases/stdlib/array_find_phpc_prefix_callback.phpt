--TEST--
stdlib array_all_key()/array_any_key() accept phpc_-prefixed user callbacks (#18489, ext/standard/array.c)
--FILE--
<?php

declare(strict_types=1);

function phpc_aot_probe_cb($key, $value): bool
{
    return is_int($value) || '1' === $value;
}

$h = ['a' => 1, 'b' => '1'];
echo array_all_key($h, 'phpc_aot_probe_cb', false) ? 'all' : 'notall';
echo array_any_key($h, 'phpc_aot_probe_cb', false) ? 'any' : 'notany';
echo function_exists('phpc_aot_probe_cb') ? 'vis' : 'hidden';
--EXPECT--
allanyhidden
