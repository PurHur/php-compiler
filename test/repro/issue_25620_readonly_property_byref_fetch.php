<?php
/**
 * Issue #25620 — &$obj->readonlyProp must Error immediately like Zend
 * (Zend/zend_readonly.c get_property_ptr_ptr), not bind then fail on write.
 *
 *   php test/repro/issue_25620_readonly_property_byref_fetch.php
 *   php bin/vm.php test/repro/issue_25620_readonly_property_byref_fetch.php
 *   php bin/jit.php test/repro/issue_25620_readonly_property_byref_fetch.php
 *   # expect: Error:Cannot modify readonly property A::$x (+ WRITE: same)
 *   # never: REF_OK
 */
class A
{
    public function __construct(public readonly int $x)
    {
    }
}
$a = new A(1);
try {
    $r = &$a->x;
    echo "REF_OK\n";
    var_export($r);
    echo "\n";
} catch (Throwable $e) {
    echo get_class($e), ':', $e->getMessage(), "\n";
}
try {
    $a->x = 2;
    echo "WRITE_OK\n";
} catch (Throwable $e) {
    echo 'WRITE:', get_class($e), ':', $e->getMessage(), "\n";
}
