--TEST--
stdlib gethostbynamel() — backed enum case TypeError (#6267, ext/standard/dns.c)
--FILE--
<?php
enum E: string { case A = 'x'; }
try {
    gethostbynamel(E::A);
    echo "uncaught\n";
} catch (TypeError $e) {
    echo $e->getMessage(), "\n";
}
--EXPECT--
gethostbynamel(): Argument #1 ($hostname) must be of type string, E given
