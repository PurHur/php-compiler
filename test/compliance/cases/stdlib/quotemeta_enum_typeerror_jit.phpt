--TEST--
stdlib quotemeta() JIT — backed enum case TypeError (#7185, ext/standard/string.c)
--FILE--
<?php
enum E: string { case A = 'x'; }
try {
    quotemeta(E::A);
    echo "uncaught\n";
} catch (TypeError $e) {
    echo $e->getMessage(), "\n";
}
echo quotemeta('a.b'), "\n";
--EXPECT--
quotemeta(): Argument #1 ($string) must be of type string, E given
a\.b
