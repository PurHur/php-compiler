--TEST--
stdlib string compare JIT — enum case operands TypeError (#5733)
--FILE--
<?php
enum S: string { case X = 'a'; }
try {
    strncmp(S::X, 'b', 1);
    echo "uncaught\n";
} catch (TypeError $e) {
    echo $e->getMessage(), "\n";
}
--EXPECT--
strncmp(): Argument #1 ($string1) must be of type string, S given
