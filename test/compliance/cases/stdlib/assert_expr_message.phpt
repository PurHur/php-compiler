--TEST--
stdlib assert() AssertionError message keeps expression text (#29630)
--INI--
zend.assertions=1
--FILE--
<?php
ini_set('zend.assertions', '1');
ini_set('assert.active', '1');
ini_set('assert.exception', '1');
try {
    assert(false);
} catch (AssertionError $e) {
    echo 'lit:', $e->getMessage(), "\n";
}
$x = 0;
try {
    assert($x === 1);
} catch (AssertionError $e) {
    echo 'expr:', $e->getMessage(), "\n";
}
try {
    assert($x === 1, 'custom');
} catch (AssertionError $e) {
    echo 'custom:', $e->getMessage(), "\n";
}
--EXPECT--
lit:assert(false)
expr:assert($x === 1)
custom:custom
