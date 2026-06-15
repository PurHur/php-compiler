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
} catch (TypeError $e) {
    echo 'TypeError: ', $e->getMessage(), "\n";
}
