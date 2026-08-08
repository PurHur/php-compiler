--TEST--
zend.assertions runtime ini_set from -1 rejected like Zend (#24396, Zend/zend.c OnUpdateAssertions)
--INI--
zend.assertions=-1
--FILE--
<?php
error_reporting(E_ALL);
ini_set('display_errors', '1');
echo 'start=', ini_get('zend.assertions'), "\n";
$set = ini_set('zend.assertions', '1');
echo 'set=', var_export($set, true), "\n";
echo 'after=', ini_get('zend.assertions'), "\n";
try {
    assert(false, 'nope');
    echo "assert=no-throw\n";
} catch (Throwable $e) {
    echo 'assert=', get_class($e), "\n";
}
--EXPECT--
start=-1

Warning: zend.assertions may be completely enabled or disabled only in php.ini
set=false
after=-1
assert=no-throw
