--TEST--
stdlib gethostbyaddr() JIT — backed enum case TypeError (#6264, ext/standard/dns.c)
--FILE--
<?php
enum E: string { case A = '127.0.0.1'; }
try {
    gethostbyaddr(E::A);
    echo "uncaught\n";
} catch (TypeError $e) {
    echo $e->getMessage(), "\n";
}
--EXPECT--
gethostbyaddr(): Argument #1 ($ip) must be of type string, E given
