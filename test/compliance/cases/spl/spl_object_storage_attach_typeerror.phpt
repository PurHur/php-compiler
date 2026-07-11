--TEST--
spl SplObjectStorage::attach() non-object TypeError message (#14357)
--FILE--
<?php
$s = new SplObjectStorage();
try {
    $s->attach(1);
    echo "no error\n";
} catch (TypeError $e) {
    echo $e->getMessage(), "\n";
}
--EXPECT--
SplObjectStorage::attach(): Argument #1 ($object) must be of type object, int given
