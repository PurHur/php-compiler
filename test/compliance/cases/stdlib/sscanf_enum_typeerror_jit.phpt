--TEST--
JIT: sscanf() — enum case string operand TypeError (#5930)
--FILE--
<?php
enum E: string { case A = '42'; }
try {
    sscanf(E::A, '%d');
    echo "uncaught\n";
} catch (TypeError $e) {
    echo $e->getMessage(), "\n";
}
--EXPECT--
sscanf(): Argument #1 ($string) must be of type string, E given
