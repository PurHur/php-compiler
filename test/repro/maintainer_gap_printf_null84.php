<?php

/**
 * #20197 — printf()/fprintf() null format under PHP_COMPILER_PROFILE=8.4.
 *
 * Expect TypeError (Z_PARAM_STR); default profile still deprecate+coerce (#18764).
 */
error_reporting(E_ALL);
try {
    echo 'printf ', var_export(printf(null), true), "\n";
} catch (Throwable $e) {
    echo 'printf ', get_class($e), "\n";
}
$fp = fopen('php://memory', 'w+');
try {
    echo 'fprintf ', var_export(fprintf($fp, null), true), "\n";
} catch (Throwable $e) {
    echo 'fprintf ', get_class($e), "\n";
}
fclose($fp);
