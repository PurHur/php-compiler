--TEST--
Language: zend.assertions=-1 compiles assert() out — no side effects, returns true (#31857, zend_compile.c)
--INI--
zend.assertions=-1
--FILE--
<?php
error_reporting(E_ALL);
echo 'zend.assertions=', var_export(ini_get('zend.assertions'), true), "\n";
$ran = false;
try {
    $ret = assert(($ran = true) && false, 'nope');
    echo 'SURVIVED ran=', $ran ? '1' : '0', ' ret=', $ret ? '1' : '0', "\n";
} catch (Throwable $e) {
    echo get_class($e), ': ', $e->getMessage(), ' ran=', $ran ? '1' : '0', "\n";
}
--EXPECT--
zend.assertions='-1'
SURVIVED ran=0 ret=1
