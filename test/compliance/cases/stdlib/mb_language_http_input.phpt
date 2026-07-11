--TEST--
stdlib mb_language() / mb_http_input() — encoding metadata (ext/mbstring/mbstring.c, #4636)
--FILE--
<?php
echo function_exists('mb_language') ? "lang_fn\n" : "lang_missing\n";
echo function_exists('mb_http_input') ? "input_fn\n" : "input_missing\n";
echo mb_language(), "\n";
var_export(mb_language('uni'));
echo "\n";
echo mb_language(), "\n";
var_export(mb_http_input());
echo "\n";
var_export(mb_http_input('G'));
echo "\n";
echo mb_http_input('L'), "\n";
var_export(mb_http_input('I'));
echo "\n";
try {
    mb_language('bogus');
} catch (ValueError $e) {
    echo $e->getMessage(), "\n";
}
try {
    mb_http_input('X');
} catch (ValueError $e) {
    echo $e->getMessage(), "\n";
}
--EXPECT--
lang_fn
input_fn
neutral
true
uni
false
false
UTF-8
array (
  0 => 'UTF-8',
)
mb_language(): Argument #1 ($language) must be a valid language, "bogus" given
mb_http_input(): Argument #1 ($type) must be one of "G", "P", "C", "S", "I", or "L"
