--TEST--
Language: final plain property post-construct write blocked (#22450, #22451, Zend/zend_object_handlers.c)
--ENV--
PHP_COMPILER_PROFILE=8.4
--FILE--
<?php
class C {
    public final string $x = "a";
}
$o = new C;
echo $o->x, "\n";
try {
    $o->x = "b";
    echo "WROTE\n";
} catch (Error $e) {
    echo "BLOCKED:", $e->getMessage(), "\n";
}
--EXPECT--
a
BLOCKED:Cannot modify final property C::$x
