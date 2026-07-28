--TEST--
UnhandledMatchError redacts string subject when zend.exception_string_param_max_len=0 (#24487)
--INI--
zend.exception_string_param_max_len=0
--FILE--
<?php
try {
    echo match ('secret-subject') { 1 => 'a' };
} catch (UnhandledMatchError $e) {
    echo $e->getMessage(), "\n";
}
// Non-string subjects keep Zend forms (#23664).
foreach ([null, true, [1]] as $i => $v) {
    try {
        match ($v) { 0 => 0 };
    } catch (UnhandledMatchError $e) {
        echo $i, ':', $e->getMessage(), "\n";
    }
}
--EXPECT--
Unhandled match case '...'
0:Unhandled match case NULL
1:Unhandled match case true
2:Unhandled match case of type array
