<?php
/**
 * Issue #25096 — list()/[] destructuring of a plain object must throw
 * Error: Cannot use object of type … as array (zend_vm_def.h FETCH_LIST).
 *
 *   php bin/vm.php test/repro/issue_25096_list_object_destructure.php
 *   php bin/jit.php test/repro/issue_25096_list_object_destructure.php
 */
try {
    $o = (object) [0 => 1, 1 => 2];
    [$a, $b] = $o;
    echo "ok:$a$b\n";
} catch (Throwable $e) {
    echo get_class($e) . ':' . $e->getMessage() . "\n";
}
try {
    $o = (object) [0 => 10, 1 => 20];
    list($a, $b) = $o;
    echo "ok:$a$b\n";
} catch (Throwable $e) {
    echo get_class($e) . ':' . $e->getMessage() . "\n";
}
