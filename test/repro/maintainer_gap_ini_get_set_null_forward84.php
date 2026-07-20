<?php
/**
 * #21312 — ini_get()/ini_set(null) soft-null under PHP_COMPILER_PROFILE=8.4
 * (reverts #20361 TypeError; Zend Z_PARAM_STR DEP+coerce).
 */
error_reporting(E_ALL);
set_error_handler(static function (int $n, string $m): bool {
    return true;
});
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
