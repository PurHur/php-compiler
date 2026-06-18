--TEST--
Language: final class child after runtime statements compile-time fatal (#9722)
--FILE--
<?php
final class C {}
try {
    new C;
    echo "ok\n";
} catch (Throwable $e) {
    echo get_class($e), "\n";
}
class D extends C {}
--EXPECT_EXIT--
255
