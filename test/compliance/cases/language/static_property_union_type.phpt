--TEST--
Language: static property union type int|string — parse, read/write, invalid assign (#8726)
--FILE--
<?php
class C {
    public static string|int $p = 'x';
}
echo C::$p, "\n";
C::$p = 42;
echo C::$p, "\n";
C::$p = 'ok';
echo C::$p, "\n";
try {
    C::$p = [];
} catch (Throwable $e) {
    echo get_class($e), ': ', $e->getMessage(), "\n";
}
--EXPECT--
x
42
ok
TypeError: Cannot assign array to property C::$p of type string|int
