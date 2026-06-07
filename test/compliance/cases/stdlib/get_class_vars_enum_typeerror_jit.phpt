--TEST--
stdlib get_class_vars() JIT — enum case operand TypeError (#7232, ext/standard/class.c)
--FILE--
<?php
declare(strict_types=1);

enum E: string { case A = 'x'; }

class C {}

try {
    get_class_vars(E::A);
    echo "uncaught\n";
} catch (TypeError $e) {
    echo $e->getMessage(), "\n";
}

var_dump(get_class_vars(C::class));
?>
--EXPECT--
get_class_vars(): Argument #1 ($class) must be of type string, E given
array(0) {
}
