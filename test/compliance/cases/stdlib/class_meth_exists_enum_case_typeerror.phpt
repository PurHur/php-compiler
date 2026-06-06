--TEST--
Stdlib: class_meth_exists() — enum case class operand TypeError (#7068, php-src-strict)
--FILE--
<?php
enum E: string { case A = 'a'; }
try {
    class_meth_exists(E::A, 'cases');
} catch (TypeError $e) {
    echo $e->getMessage(), "\n";
}
--EXPECT--
class_meth_exists(): Argument #1 ($class) must be of type string, E given
