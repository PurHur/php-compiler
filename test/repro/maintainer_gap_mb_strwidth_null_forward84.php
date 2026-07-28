<?php
// #24257 — mb_strwidth(null) TypeError under PROFILE=8.4; Zend DEP+coerce → 0
error_reporting(E_ALL);
$seen = [];
set_error_handler(static function (int $no, string $str) use (&$seen): bool {
    if (E_DEPRECATED === $no) {
        $seen[] = $str;
    }

    return true;
});
try {
    echo 'mb_strwidth=', var_export(mb_strwidth(null), true), "\n";
} catch (TypeError $e) {
    echo 'mb_strwidth: TypeError: ', $e->getMessage(), "\n";
}
try {
    echo 'mb_strlen=', var_export(mb_strlen(null), true), "\n";
} catch (TypeError $e) {
    echo 'mb_strlen: TypeError: ', $e->getMessage(), "\n";
}
restore_error_handler();
echo 'depr=', (int) (count($seen) >= 2), "\n";
