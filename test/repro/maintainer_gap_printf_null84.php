<?php

/**
 * #21234 — printf()/fprintf() null format under PHP_COMPILER_PROFILE=8.4.
 *
 * Expect DEP+coerce (Zend 8.4); reverts #20197 TypeError expectation.
 */
error_reporting(E_ALL);
$deps = 0;
set_error_handler(static function (int $no, string $msg) use (&$deps): bool {
    if (E_DEPRECATED === $no) {
        ++$deps;
    }

    return true;
});
try {
    echo 'printf ', var_export(printf(null), true), ($deps >= 1 ? ' DEP' : ''), "\n";
} catch (Throwable $e) {
    echo 'printf ', get_class($e), "\n";
    exit(1);
}
$fp = fopen('php://memory', 'w+');
$prev = $deps;
try {
    echo 'fprintf ', var_export(fprintf($fp, null), true), ($deps > $prev ? ' DEP' : ''), "\n";
} catch (Throwable $e) {
    echo 'fprintf ', get_class($e), "\n";
    exit(1);
}
fclose($fp);
