--TEST--
Language: propertyIsInitialized() — typed property init probe (#6513)
--FILE--
<?php
class Box {
    public int $count;
    public function probe(): bool {
        return $this->propertyIsInitialized('count');
    }
}
$b = new Box();
var_export($b->probe());
$b->count = 1;
var_export($b->probe());
echo "\n";
try {
    $b->propertyIsInitialized('missing');
} catch (Error $e) {
    echo get_class($e), ': ', $e->getMessage(), "\n";
}
--EXPECT--
falsetrue
Error: Property Box::$missing does not exist
