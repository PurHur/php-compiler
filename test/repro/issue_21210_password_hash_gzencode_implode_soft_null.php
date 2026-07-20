<?php
// Repro #21210 — password_hash/gzencode/implode soft-null under PROFILE=8.4
error_reporting(E_ALL);
set_error_handler(static function (int $no, string $str): bool {
    if (E_DEPRECATED === $no) {
        echo "DEP\n";
    }

    return true;
});
$h = password_hash(null, PASSWORD_DEFAULT);
echo 'password_hash ', is_string($h) && str_starts_with($h, '$2y$') ? 'OK' : 'BAD', "\n";
$g = gzencode(null);
echo 'gzencode ', strlen($g) === strlen(gzencode('')) ? 'OK' : 'BAD', "\n";
$i = implode(null, ['a', 'b']);
echo 'implode ', 'ab' === $i ? 'OK' : 'BAD', "\n";
