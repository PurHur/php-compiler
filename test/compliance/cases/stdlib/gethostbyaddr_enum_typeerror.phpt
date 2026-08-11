--TEST--
stdlib gethostbyaddr() — backed enum case TypeError (#5854, ext/standard/dns.c)
--FILE--
<?php
enum E: string { case A = 'x'; }
try {
    gethostbyaddr(E::A);
} catch (TypeError $e) {
    echo $e->getMessage(), "\n";
}
--EXPECT--
gethostbyaddr(): Argument #1 ($ip) must be of type string, E given
