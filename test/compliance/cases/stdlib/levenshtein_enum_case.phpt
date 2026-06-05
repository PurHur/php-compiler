--TEST--
stdlib levenshtein() — backed enum case TypeError (#5833, ext/standard/levenshtein.c)
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
