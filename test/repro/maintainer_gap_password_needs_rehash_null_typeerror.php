<?php
// #21314 (re-#18655) — password_needs_rehash(null) soft-null DEP+coerce on 8.4
// (php-src ext/standard/password.c Z_PARAM_STR(hash))
error_reporting(E_ALL);
$seen = 0;
set_error_handler(static function (int $no) use (&$seen): bool {
    if (E_DEPRECATED === $no) {
        $seen++;
    }
    return true;
});
try {
    echo var_export(password_needs_rehash(null, PASSWORD_DEFAULT), true), "\n";
} catch (TypeError $e) {
    echo $e->getMessage(), "\n";
}
restore_error_handler();
echo 'depr=', (int) ($seen >= 1), "\n";
