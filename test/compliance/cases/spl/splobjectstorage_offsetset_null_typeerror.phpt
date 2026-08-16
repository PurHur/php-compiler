--TEST--
spl SplObjectStorage::offsetSet(null)/dim TypeError cites offsetSet (#31509)
--FILE--
<?php
$s = new SplObjectStorage();
try {
    $s->offsetSet(null, 1);
    echo "no error\n";
} catch (TypeError $e) {
    echo 'off:', $e->getMessage(), "\n";
}
try {
    $s[null] = 1;
    echo "no error\n";
} catch (TypeError $e) {
    echo 'dim:', $e->getMessage(), "\n";
}
--EXPECT--
off:SplObjectStorage::offsetSet(): Argument #1 ($object) must be of type object, null given
dim:SplObjectStorage::offsetSet(): Argument #1 ($object) must be of type object, null given
