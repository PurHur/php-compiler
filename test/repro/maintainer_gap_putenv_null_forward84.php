<?php
/**
 * #21312 — putenv(null) soft-null then ValueError under PHP_COMPILER_PROFILE=8.4
 * (reverts #21004 TypeError; Zend Z_PARAM_STR DEP+coerce).
 */
error_reporting(E_ALL);
set_error_handler(static function (int $n, string $m): bool {
    return true;
});
try {
    var_export(putenv(null));
    echo " COERCED\n";
} catch (Throwable $e) {
    echo get_class($e), ': ', $e->getMessage(), "\n";
}
