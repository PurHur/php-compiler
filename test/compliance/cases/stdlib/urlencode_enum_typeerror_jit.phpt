--TEST--
stdlib parse_url()/urlencode()/rawurlencode() JIT — backed enum case TypeError (#5860)
--FILE--
<?php
enum E: string { case A = 'http://x'; }
try {
    parse_url(E::A);
    echo "parse_url uncaught\n";
} catch (TypeError $e) {
    echo $e->getMessage(), "\n";
}
try {
    urlencode(E::A);
    echo "urlencode uncaught\n";
} catch (TypeError $e) {
    echo $e->getMessage(), "\n";
}
try {
    rawurlencode(E::A);
    echo "rawurlencode uncaught\n";
} catch (TypeError $e) {
    echo $e->getMessage(), "\n";
}
--EXPECT--
parse_url(): Argument #1 ($url) must be of type string, E given
urlencode(): Argument #1 ($string) must be of type string, E given
rawurlencode(): Argument #1 ($string) must be of type string, E given
