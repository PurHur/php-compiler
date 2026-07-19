<?php

/**
 * #20361 — ini_get()/ini_set(null) TypeError under PHP_COMPILER_PROFILE=8.4.
 * php-src: ext/standard/basic_functions.stub.php — string $option.
 */
try {
    var_export(ini_get(null));
    echo " COERCED ini_get\n";
} catch (Throwable $e) {
    echo get_class($e), " ini_get\n";
}

try {
    var_export(ini_set(null, '1'));
    echo " COERCED ini_set\n";
} catch (Throwable $e) {
    echo get_class($e), " ini_set\n";
}
