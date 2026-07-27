--TEST--
UnhandledMatchError messages match Zend smart_str_append_scalar / of-type forms (#23664)
--INI--
zend.exception_string_param_max_len=15
--FILE--
<?php
foreach ([
    'str' => 'secret-value',
    'int' => 42,
    'null' => null,
    'true' => true,
    'arr' => [1],
] as $label => $v) {
    try {
        match ($v) { 0 => 0, 'nope' => 1 };
        echo "$label:no\n";
    } catch (UnhandledMatchError $e) {
        echo "$label:", $e->getMessage(), "\n";
    }
}
// max_len=0 redacts non-empty strings to '...'
ini_set('zend.exception_string_param_max_len', '0');
try {
    match ('secret-value') { 0 => 0 };
} catch (UnhandledMatchError $e) {
    echo 'str0:', $e->getMessage(), "\n";
}
--EXPECT--
str:Unhandled match case 'secret-value'
int:Unhandled match case 42
null:Unhandled match case NULL
true:Unhandled match case true
arr:Unhandled match case of type array
str0:Unhandled match case '...'
