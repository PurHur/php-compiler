<?php
// Issue #14033 — class_implements(SplObjectStorage) must list core SPL interfaces.
$ifaces = class_implements(new SplObjectStorage());
foreach (['Countable', 'Iterator', 'Traversable', 'Serializable'] as $name) {
    if (!isset($ifaces[$name])) {
        echo 'missing interface: ', $name, "\n";
        var_export($ifaces);
        echo "\n";
        exit(1);
    }
}
echo "ok\n";
