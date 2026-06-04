--TEST--
AOT hash_equals() — enum case operand TypeError (#5760)
--FILE--
<?php
declare(strict_types=1);

enum E: string { case A = 'x'; }

try {
    hash_equals(E::A, 'x');
    echo "uncaught\n";
} catch (TypeError $e) {
    echo $e->getMessage(), "\n";
}
?>
--EXPECT--
hash_equals(): Argument #1 ($known_string) must be of type string, E given
