--TEST--
Language: zend.exception_string_param_max_len compiled default 15 (php -n, #30626)
--FILE--
<?php
echo 'max=', ini_get('zend.exception_string_param_max_len'), "\n";
try {
    match('hello') { 'a' => 1 };
} catch (UnhandledMatchError $e) {
    echo $e->getMessage(), "\n";
}
ini_set('zend.exception_string_param_max_len', '0');
try {
    match('hello') { 'a' => 1 };
} catch (UnhandledMatchError $e) {
    echo $e->getMessage(), "\n";
}
--EXPECT--
max=15
Unhandled match case 'hello'
Unhandled match case '...'
