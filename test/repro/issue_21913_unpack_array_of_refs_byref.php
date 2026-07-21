<?php
/** Repro #21913 — unpack array-of-refs into by-ref formal (Zend ZEND_SEND_UNPACK). */
function f(&$a) {
    $a = 5;
}
$x = 1;
$args = [&$x];
try {
    f(...$args);
    echo "ok x=$x\n";
} catch (Throwable $e) {
    echo get_class($e), ': ', $e->getMessage(), "\n";
}
