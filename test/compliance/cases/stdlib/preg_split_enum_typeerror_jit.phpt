--TEST--
stdlib preg_split() JIT — enum case subject TypeError (#5999)
--FILE--
<?php
declare(strict_types=1);
enum E: string { case A = 'x'; }
try {
    preg_split('/a/', E::A);
    echo "uncaught\n";
} catch (TypeError $e) {
    echo $e->getMessage(), "\n";
}
--EXPECT--
preg_split(): Argument #2 ($subject) must be of type string, E given
