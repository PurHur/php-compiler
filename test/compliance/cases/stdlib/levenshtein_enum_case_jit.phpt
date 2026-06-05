--TEST--
stdlib levenshtein() JIT — backed enum case TypeError (#5833)
--FILE--
<?php
enum E: string { case A = 'kitten'; }
try {
    levenshtein(E::A, 'sitting');
    echo "uncaught\n";
} catch (TypeError $e) {
    echo $e->getMessage(), "\n";
}
--EXPECT--
levenshtein(): Argument #1 ($string1) must be of type string, E given
