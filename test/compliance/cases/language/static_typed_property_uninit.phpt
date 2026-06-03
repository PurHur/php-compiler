--TEST--
Language: uninitialized static typed property read throws Error (#4908)
--FILE--
<?php
class C {
    public static int $x;
}
try {
    echo C::$x;
} catch (Error $e) {
    echo $e->getMessage(), "\n";
}
C::$x = 1;
echo C::$x, "\n";
--EXPECT--
Typed static property C::$x must not be accessed before initialization
1
