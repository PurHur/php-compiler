--TEST--
AOT: array_any()/array_all()/array_any_key()/array_all_key() PHP 8.4 forward profile (#16988, ext/standard/array.c)
--ENV--
PHP_COMPILER_PROFILE=8.4
--FILE--
<?php
declare(strict_types=1);

function phpc_aot_any_key_y($v, $k)
{
    return $k === 'y' && $v === 2;
}

function phpc_aot_all_key_strings($v, $k)
{
    return is_string($k) && $v > 0;
}

function phpc_aot_any_two($v)
{
    return $v === 2;
}

function phpc_aot_all_pos($v)
{
    return $v > 0;
}

$a = ['x' => 1, 'y' => 2, 'z' => 3];
echo array_any_key($a, 'phpc_aot_any_key_y') ? 'T' : 'F';
echo array_all_key($a, 'phpc_aot_all_key_strings') ? 'T' : 'F';
echo array_any([], 'phpc_aot_any_two') ? 'T' : 'F';
echo array_all([], 'phpc_aot_all_pos') ? 'T' : 'F';
echo array_any([1, 2, 3], 'phpc_aot_any_two') ? 'T' : 'F';
echo array_all([1, 2, 3], 'phpc_aot_all_pos') ? 'T' : 'F';
--EXPECT--
TTFTTT
