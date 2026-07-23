<?php
/**
 * Issue #22584 — phantom builtins must not advertise under PROFILE=8.4.
 * php-src: ext/standard/basic_functions.stub.php (no attribute_exists/class_meth_exists/unitenum_exists/crc32c)
 */
foreach (['attribute_exists', 'class_meth_exists', 'unitenum_exists', 'crc32c'] as $f) {
    echo $f, '=', function_exists($f) ? '1' : '0', "\n";
}
