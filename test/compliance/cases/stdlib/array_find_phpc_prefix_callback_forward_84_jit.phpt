--TEST--
stdlib array_find family phpc_-prefixed user callbacks JIT (#18489, ext/standard/array.c)
--ENV--
PHP_COMPILER_PROFILE=8.4
--FILE--
<?php
function phpc_aot_all_key_returns_int($k, $v)
{
    return $v == 1 ? 1 : 0;
}
function phpc_aot_any_key_returns_int($k, $v)
{
    return $v == 1 ? 1 : 0;
}
$h = ['a' => 1, 'b' => '1'];
echo array_all_key($h, 'phpc_aot_all_key_returns_int', true) ? 'T' : 'F';
echo array_any_key($h, 'phpc_aot_any_key_returns_int', false) ? 'T' : 'F';
--EXPECT--
FT
