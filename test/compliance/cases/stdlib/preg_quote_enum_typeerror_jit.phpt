--TEST--
stdlib preg_quote() JIT — enum case operand TypeError (#5999)
--FILE--
<?php
declare(strict_types=1);
enum E: string { case A = 'x'; }
try {
    preg_quote(E::A);
    echo "uncaught\n";
} catch (TypeError $e) {
    echo $e->getMessage(), "\n";
}
--EXPECT--
preg_quote(): Argument #1 ($str) must be of type string, E given
