<?php
// Issue #29631 — get_parent_class(false/true) TypeError actual label (zend_zval_value_name).
foreach ([false, true] as $v) {
    try {
        get_parent_class($v);
    } catch (Throwable $e) {
        echo get_class($e), ': ', $e->getMessage(), "\n";
    }
}
