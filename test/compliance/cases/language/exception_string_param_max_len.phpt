--TEST--
Language: zend.exception_string_param_max_len truncates getTraceAsString string args (Zend/zend_exceptions.c, #21999)
--FILE--
<?php
ini_set('zend.exception_ignore_args', '0');
function f(string $a) {
    throw new Exception('boom');
}

ini_set('zend.exception_string_param_max_len', '5');
try {
    f('abcdefghijklmnop');
} catch (Exception $e) {
    $line = explode("\n", $e->getTraceAsString())[0];
    echo (preg_match("/f\\('abcde\\.\\.\\.'\\)/", $line) ? 'max5_ok' : 'max5_bad:'.$line), "\n";
    echo $e->getTrace()[0]['args'][0] === 'abcdefghijklmnop' ? 'raw_full' : 'raw_truncated', "\n";
}

ini_set('zend.exception_string_param_max_len', '0');
try {
    f('abcdefghijklmnop');
} catch (Exception $e) {
    $line = explode("\n", $e->getTraceAsString())[0];
    echo (preg_match("/f\\('\\.\\.\\.'\\)/", $line) ? 'max0_ok' : 'max0_bad:'.$line), "\n";
}

ini_set('zend.exception_string_param_max_len', '5');
try {
    f('abc');
} catch (Exception $e) {
    $line = explode("\n", $e->getTraceAsString())[0];
    echo (preg_match("/f\\('abc'\\)/", $line) ? 'short_ok' : 'short_bad:'.$line), "\n";
}

var_export(ini_set('zend.exception_string_param_max_len', '-1'));
echo "\n";
echo ini_get('zend.exception_string_param_max_len'), "\n";
--EXPECT--
max5_ok
raw_full
max0_ok
short_ok
false
5
