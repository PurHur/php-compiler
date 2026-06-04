--TEST--
Language: clone backed enum case throws Error with Zend message (#5535, zend_enum.c)
--FILE--
<?php
enum E: int { case A = 1; }
enum Status: string { case Active = 'active'; }

try {
    clone E::A;
} catch (Throwable $e) {
    echo get_class($e), ': ', $e->getMessage(), "\n";
}

try {
    clone Status::Active;
} catch (Throwable $e) {
    echo get_class($e), ': ', $e->getMessage(), "\n";
}
--EXPECT--
Error: Trying to clone an uncloneable object of class E
Error: Trying to clone an uncloneable object of class Status
