--TEST--
stdlib array_all()/array_any() accept phpc_-prefixed user callbacks (#18489, #24000, ext/standard/array.c)
--FILE--
<?php

declare(strict_types=1);

function phpc_aot_probe_cb($value): bool
{
    return is_int($value) || '1' === $value;
}

$h = ['a' => 1, 'b' => '1'];
echo array_all($h, 'phpc_aot_probe_cb') ? 'all' : 'notall';
echo array_any($h, 'phpc_aot_probe_cb') ? 'any' : 'notany';
echo function_exists('phpc_aot_probe_cb') ? 'vis' : 'hidden';
--EXPECT--
allanyhidden
