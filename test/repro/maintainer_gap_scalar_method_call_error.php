<?php
// Issue #15529: scalar/null method call must throw catchable Error (Zend/zend_execute.c).
try {
    (1)->m();
} catch (Throwable $e) {
    echo get_class($e) . ': ' . $e->getMessage() . "\n";
}
