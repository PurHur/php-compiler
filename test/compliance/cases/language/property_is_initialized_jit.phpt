--TEST--
Language: propertyIsInitialized() — JIT compile path (#6651)
--FILE--
<?php
function probe($object) {
    return $object->propertyIsInitialized('slot');
}
class Holder {
    public $slot;
}
$b = new Holder();
var_export(probe($b));
$b->slot = 1;
var_export(probe($b));
echo "\n";
try {
    $b->propertyIsInitialized('missing');
} catch (Error $e) {
    echo get_class($e), ': ', $e->getMessage(), "\n";
}
--EXPECT--
falsetrue
Error: Property Holder::$missing does not exist
