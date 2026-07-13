--TEST--
stdlib array_find family accepts phpc_-prefixed user function callbacks (#18489, ext/standard/array.c)
--ENV--
PHP_COMPILER_PROFILE=8.4
--FILE--
<?php
function phpc_aot_probe_cb($k, $v)
{
    return 'a' === $k && 1 === $v;
}
$h = ['a' => 1, 'b' => 2];
echo call_user_func('phpc_aot_probe_cb', 'a', 1) ? 'T' : 'F';
echo function_exists('phpc_aot_probe_cb') ? 'T' : 'F';
echo array_all_key($h, 'phpc_aot_probe_cb', false) ? 'T' : 'F';
echo array_any_key($h, 'phpc_aot_probe_cb', false) ? 'T' : 'F';
--EXPECT--
TFFT
