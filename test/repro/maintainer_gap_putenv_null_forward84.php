<?php
/**
 * #21004 — putenv(null) TypeError under PHP_COMPILER_PROFILE=8.4 (re-#17041).
 * php-src: ext/standard/basic_functions.c — PHP_FUNCTION(putenv) / Z_PARAM_STR
 */
try {
    var_export(putenv(null));
    echo " COERCED\n";
} catch (Throwable $e) {
    echo get_class($e), ': ', $e->getMessage(), "\n";
}
