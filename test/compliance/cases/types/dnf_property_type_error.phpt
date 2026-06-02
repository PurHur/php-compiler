--TEST--
DNF property rejects incompatible writes with catchable TypeError (#3094)
--FILE--
<?php
interface A {}
interface B {}
class Holder {
    public (A&B)|null $item;
}
$h = new Holder();
try {
    $h->item = [];
    echo "array ok\n";
} catch (Throwable $e) {
    echo get_class($e), ': ', $e->getMessage(), "\n";
}
?>
--EXPECT--
TypeError: Cannot assign array to property Holder::$item of type (A&B)|null
