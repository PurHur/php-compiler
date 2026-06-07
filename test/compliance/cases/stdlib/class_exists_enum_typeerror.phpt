--TEST--
stdlib class_exists() — enum case operand TypeError (#7217, ext/standard/basic_functions.c)
--FILE--
<?php
declare(strict_types=1);

enum E: string { case A = 'x'; }

try {
    class_exists(E::A);
    echo "uncaught\n";
} catch (TypeError $e) {
    echo $e->getMessage(), "\n";
}

var_dump(class_exists('stdClass'));
?>
--EXPECT--
class_exists(): Argument #1 ($class) must be of type string, E given
bool(true)
