--TEST--
stdlib glob() — enum case pattern TypeError (#5732, ext/standard/dir.c)
--FILE--
<?php
enum E { case A; }
enum Es: string { case P = '*.txt'; }
foreach ([E::A, Es::P] as $pattern) {
    try {
        glob($pattern);
        echo "uncaught\n";
    } catch (TypeError $e) {
        echo $e->getMessage(), "\n";
    }
}
--EXPECT--
glob(): Argument #1 ($pattern) must be of type string, E given
glob(): Argument #1 ($pattern) must be of type string, Es given
