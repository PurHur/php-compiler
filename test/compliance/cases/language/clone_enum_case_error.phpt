--TEST--
Language: clone() on enum case throws Error (#3554)
--FILE--
<?php
enum E { case A; }
enum Status: string { case Active = 'active'; }

try {
    clone E::A;
} catch (Throwable $e) {
    echo get_class($e), "\n";
}

try {
    clone Status::Active;
} catch (Throwable $e) {
    echo get_class($e), "\n";
}
--EXPECT--
Error
Error
