<?php
// Repro #21188 / re-#19272 — urlencode family soft-null under PHP_COMPILER_PROFILE=8.4
set_error_handler(static function (int $no): bool {
    return E_DEPRECATED === $no;
});
foreach (['urlencode', 'rawurlencode', 'urldecode', 'rawurldecode'] as $fn) {
    try {
        echo "$fn: ", var_export($fn(null), true), "\n";
    } catch (TypeError $e) {
        echo $fn.': TypeError'."\n";
    }
}
echo "ok\n";
