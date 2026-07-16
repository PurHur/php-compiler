--TEST--
stdlib hash() unknown algorithm ValueError (#4186)
--FILE--
<?php
try {
    hash('nope', 'x');
    echo "uncaught\n";
} catch (ValueError $e) {
    echo $e->getMessage(), "\n";
}
try {
    hash('fnv999', 'data');
    echo "fnv999 uncaught\n";
} catch (ValueError $e) {
    echo $e->getMessage(), "\n";
}
echo hash('sha256', 'data'), "\n";
?>
--EXPECT--
hash(): Argument #1 ($algo) must be a valid hashing algorithm
hash(): Argument #1 ($algo) must be a valid hashing algorithm
3a6eb0790f39ac87c94f3856b2dd2c5d110e6811602261a9a923d3bb23adc8b7
