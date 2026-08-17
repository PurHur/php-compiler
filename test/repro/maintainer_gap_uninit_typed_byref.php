<?php
/**
 * &$obj->typed on uninitialized non-nullable property.
 * Zend: Error "Cannot access uninitialized non-nullable property C::$x by reference"
 * VM: assignment does not throw; later uses of $r warn undefined / are null.
 */
error_reporting(E_ALL);

class C
{
    public int $x;
}

$o = new C();
try {
    $r = &$o->x;
    echo "ref_ok type=" . gettype($r) . " val=" . var_export($r, true) . "\n";
} catch (Throwable $e) {
    echo "ref=" . get_class($e) . ":" . $e->getMessage() . "\n";
}
echo "after\n";
