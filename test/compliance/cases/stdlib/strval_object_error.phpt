--TEST--
stdlib strval() on object without __toString throws Error (#11303, ext/standard/type.c)
--FILE--
<?php
try {
    strval(new stdClass());
} catch (Error $e) {
    echo get_class($e), ': ', $e->getMessage(), "\n";
}
class StringableObj {
    public function __toString(): string { return 'ok'; }
}
echo strval(new StringableObj()), "\n";
--EXPECT--
Error: Object of class stdClass could not be converted to string
ok
