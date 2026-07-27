--TEST--
AOT: final plain property write rejected after construction (#23665, #22450)
--ENV--
PHP_COMPILER_PROFILE=8.4
--FILE--
<?php
class C {
    public final string $x = "a";
}
$o = new C;
try {
    $o->x = "b";
    echo "WROTE\n";
} catch (Error $e) {
    echo "BLOCKED:", $e->getMessage(), "\n";
}
--EXPECT--
BLOCKED:Cannot modify final property C::$x
