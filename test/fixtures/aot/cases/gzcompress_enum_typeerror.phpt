--TEST--
AOT: gzcompress() enum case TypeError (#6371)
--FILE--
<?php
enum E: string { case A = 'hi'; }
try {
    gzcompress(E::A);
    echo "uncaught\n";
} catch (TypeError $e) {
    echo $e->getMessage(), "\n";
}
--EXPECT--
gzcompress(): Argument #1 ($data) must be of type string, E given
