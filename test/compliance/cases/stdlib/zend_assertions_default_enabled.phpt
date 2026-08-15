--TEST--
zend.assertions=1 at startup: assert(false) throws (#28823 / #31195, Zend/zend_ini.c)
--INI--
zend.assertions=1
--FILE--
<?php
error_reporting(E_ALL);
echo 'assertions=', ini_get('zend.assertions'), ' exception=', ini_get('assert.exception'), "\n";
try {
    assert(false, 'msg');
    echo "NO_THROW\n";
} catch (Throwable $e) {
    echo 'THROW ', get_class($e), "\n";
}
--EXPECT--
assertions=1 exception=1
THROW AssertionError
