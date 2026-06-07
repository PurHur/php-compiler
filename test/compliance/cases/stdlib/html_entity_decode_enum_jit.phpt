--TEST--
stdlib html_entity_decode() JIT — backed enum case TypeError (#5899)
--FILE--
<?php
enum E: string { case A = "x"; }

try {
    html_entity_decode(E::A);
    echo "uncaught\n";
} catch (TypeError $e) {
    echo $e->getMessage(), "\n";
}
--EXPECT--
html_entity_decode(): Argument #1 ($string) must be of type string, E given
