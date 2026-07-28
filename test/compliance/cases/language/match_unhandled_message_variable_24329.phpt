--TEST--
UnhandledMatchError formats variable subjects (not always NULL) (#24329, re-#23664)
--INI--
zend.exception_string_param_max_len=15
--FILE--
<?php
foreach ([
    'int' => 3,
    'str' => 'secret-value',
    'null' => null,
    'true' => true,
    'false' => false,
    'arr' => [1],
] as $label => $v) {
    try {
        match ($v) { 0 => 0, 'nope' => 1 };
        echo "$label:no\n";
    } catch (UnhandledMatchError $e) {
        echo $label, ':', $e->getMessage(), "\n";
    }
}
try {
    match (false) { 0 => 0 };
} catch (UnhandledMatchError $e) {
    echo 'litfalse:', $e->getMessage(), "\n";
}
--EXPECT--
int:Unhandled match case 3
str:Unhandled match case 'secret-value'
null:Unhandled match case NULL
true:Unhandled match case true
false:Unhandled match case false
arr:Unhandled match case of type array
litfalse:Unhandled match case false
