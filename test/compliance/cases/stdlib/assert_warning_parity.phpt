--TEST--
stdlib assert() failure warning — E_WARNING assert(): … failed (#23731, ext/standard/assert.c)
--FILE--
<?php
ini_set('zend.assertions', '1');
ini_set('assert.exception', '0');
error_reporting(E_ALL);
set_error_handler(static function (int $no, string $str): bool {
    echo "W:$no:$str\n";
    return true;
});
assert(false);
assert(false, 'x');
--EXPECT--
W:2:assert(): assert(false) failed
W:2:assert(): x failed
