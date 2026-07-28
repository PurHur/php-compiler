--TEST--
stdlib assert() — Stringable $description TypeError (#18191, ext/standard/assert.c)
--INI--
zend.assertions=1
--FILE--
<?php
ini_set('assert.active', '1');
ini_set('zend.assertions', '1');
ini_set('assert.exception', '1');
class Msg {
    public function __toString(): string { return 'x'; }
}
try {
    assert(false, new Msg());
    echo "no_error\n";
} catch (TypeError $e) {
    echo get_class($e), "\n";
}
try {
    assert(false, 'msg');
    echo "string_fail\n";
} catch (AssertionError $e) {
    echo 'AssertionError:', $e->getMessage(), "\n";
}
?>
--EXPECT--
TypeError
AssertionError:msg
