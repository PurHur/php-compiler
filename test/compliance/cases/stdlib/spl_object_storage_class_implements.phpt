--TEST--
SplObjectStorage class_implements() lists Countable, Iterator, Traversable, Serializable (#14033)
--FILE--
<?php
$ifaces = class_implements(new SplObjectStorage());
foreach (['Countable', 'Iterator', 'Traversable', 'Serializable'] as $name) {
    if (!isset($ifaces[$name])) {
        echo 'missing:', $name, "\n";
        exit(1);
    }
}
echo "ok\n";
?>
--EXPECT--
ok
