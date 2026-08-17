--TEST--
Language: zend.assertions=1 still evaluates assert() condition side effects (#31857)
--INI--
zend.assertions=1
--FILE--
<?php
error_reporting(E_ALL);
$ran = false;
try {
    assert(($ran = true) && false, 'nope');
    echo 'NO_THROW ran=', $ran ? '1' : '0', "\n";
} catch (AssertionError $e) {
    echo 'caught ran=', $ran ? '1' : '0', "\n";
} catch (Throwable $e) {
    echo get_class($e), ' ran=', $ran ? '1' : '0', "\n";
}
--EXPECT--
caught ran=1
