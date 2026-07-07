--TEST--
stdlib phpversion() — enum extension operand TypeError (#17196, ext/standard/info.c)
--FILE--
<?php
enum E: int { case A = 1; }

try {
    phpversion(E::A);
    echo "uncaught\n";
} catch (TypeError $e) {
    echo $e->getMessage(), "\n";
}

var_dump(is_string(phpversion()));
var_dump(phpversion(null) === phpversion());
?>
--EXPECT--
phpversion(): Argument #1 ($extension) must be of type ?string, E given
bool(true)
bool(true)
