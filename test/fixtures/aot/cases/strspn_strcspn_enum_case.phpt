--TEST--
AOT: strspn()/strcspn() — TypeError for enum case operands
--FILE--
<?php
enum E: string { case A = 'abc'; }
try {
    strspn(E::A, 'a');
    echo "uncaught\n";
} catch (TypeError $e) {
    echo $e->getMessage(), "\n";
}
--EXPECT--
strspn(): Argument #1 ($string) must be of type string, E given
