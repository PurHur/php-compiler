<?php

/**
 * Maintainer gap repro for #29777 — mb_convert_encoding(null) under strict_types.
 * Zend: TypeError; pre-fix VM: Deprecated + ''.
 */
declare(strict_types=1);

try {
    var_export(mb_convert_encoding(null, 'UTF-8'));
    echo "\nfail: coerced\n";
} catch (TypeError $e) {
    echo 'ok:', $e->getMessage(), "\n";
}
