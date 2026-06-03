--TEST--
stdlib ksort() TypeError on non-array (issue #5250, ext/standard/array.c)
--FILE--
<?php
$o = new stdClass;
try {
    ksort($o);
} catch (TypeError $e) {
    echo 'TypeError:', $e->getMessage(), "\n";
} catch (Throwable $e) {
    echo get_class($e), ':', $e->getMessage(), "\n";
}
--EXPECT--
TypeError:ksort(): Argument #1 ($array) must be of type array, stdClass given
