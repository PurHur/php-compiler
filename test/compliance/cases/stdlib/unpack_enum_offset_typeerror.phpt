--TEST--
stdlib unpack() $offset — enum case operand TypeError (#8866, ext/standard/pack.c)
--FILE--
<?php
declare(strict_types=1);
enum E: int { case A = 5; }
try {
    unpack('i', pack('i', 1), E::A);
    echo "no throw\n";
} catch (Throwable $e) {
    echo get_class($e), ': ', $e->getMessage(), "\n";
}
?>
--EXPECT--
TypeError: unpack(): Argument #3 ($offset) must be of type int, E given
