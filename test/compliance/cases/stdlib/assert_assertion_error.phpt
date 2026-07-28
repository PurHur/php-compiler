--TEST--
Stdlib: assert() throws AssertionError when assert.exception=1 (#3316)
--INI--
zend.assertions=1
--FILE--
<?php
ini_set('zend.assertions', '1');
ini_set('assert.active', '1');
ini_set('assert.exception', '1');
try {
    assert(false, 'fail');
    echo "no throw\n";
} catch (AssertionError $e) {
    echo 'caught:', $e->getMessage(), "\n";
} catch (Throwable $e) {
    echo 'wrong:', get_class($e), ':', $e->getMessage(), "\n";
}
--EXPECT--
caught:fail
