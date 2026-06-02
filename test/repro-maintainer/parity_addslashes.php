<?php

declare(strict_types=1);

/**
 * Maintainer repro for #4553 — addslashes()/stripslashes() scalar coercion vs Zend.
 */

var_dump(addslashes(null));
var_dump(stripslashes(123));

try {
    addslashes([]);
} catch (Throwable $e) {
    echo 'array: ', $e::class, ' ', $e->getMessage(), "\n";
}

try {
    addslashes(new stdClass());
} catch (Throwable $e) {
    echo 'object: ', $e::class, ' ', $e->getMessage(), "\n";
}
