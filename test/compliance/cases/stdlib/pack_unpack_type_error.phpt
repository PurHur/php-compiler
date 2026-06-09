--TEST--
stdlib pack()/unpack() — TypeError for wrong argument types (#4676, ext/standard/pack.c)
--FILE--
<?php
try {
    pack([], 1);
    echo "pack uncaught\n";
} catch (TypeError $e) {
    echo $e->getMessage(), "\n";
}
try {
    unpack('C', []);
    echo "unpack data uncaught\n";
} catch (TypeError $e) {
    echo $e->getMessage(), "\n";
}
try {
    unpack('C', 'ab', []);
    echo "unpack offset uncaught\n";
} catch (TypeError $e) {
    echo $e->getMessage(), "\n";
}
--EXPECT--
pack(): Argument #1 ($format) must be of type string, array given
unpack(): Argument #2 ($string) must be of type string, array given
unpack(): Argument #3 ($offset) must be of type int, array given
