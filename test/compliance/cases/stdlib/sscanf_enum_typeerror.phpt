--TEST--
stdlib sscanf() — enum case string operand TypeError (#5930, ext/standard/sscanf.c)
--FILE--
<?php
enum E: string { case A = '42'; }
try {
    sscanf(E::A, '%d');
    echo "uncaught\n";
} catch (TypeError $e) {
    echo $e->getMessage(), "\n";
}
try {
    sscanf('42', E::A);
    echo "uncaught format\n";
} catch (TypeError $e) {
    echo $e->getMessage(), "\n";
}
--EXPECT--
sscanf(): Argument #1 ($string) must be of type string, E given
sscanf(): Argument #2 ($format) must be of type string, E given
