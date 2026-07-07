--TEST--
stdlib phpversion() JIT — enum extension operand TypeError (#17196, ext/standard/info.c)
--FILE--
<?php
enum E: int { case A = 1; }
try {
    phpversion(E::A);
    echo "uncaught\n";
} catch (TypeError $e) {
    echo $e->getMessage(), "\n";
}
--EXPECT--
phpversion(): Argument #1 ($extension) must be of type ?string, E given
