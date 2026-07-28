--TEST--
Stdlib: assert() AssertionError JIT (#3316)
--JIT--
--INI--
zend.assertions=1
--FILE--
<?php
ini_set('zend.assertions', '1');
ini_set('assert.exception', '1');
try {
    assert(false, 'fail');
    echo "no throw\n";
} catch (AssertionError $e) {
    echo 'caught:', $e->getMessage(), "\n";
}
--EXPECT--
caught:fail
