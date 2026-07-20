<?php
// Repro #21280 — str_rot13/crypt/uniqid/gzcompress soft-null under PROFILE=8.4
error_reporting(E_ALL);
set_error_handler(static function (int $no, string $str): bool {
    if (E_DEPRECATED === $no) {
        echo "DEP\n";
    }

    return true;
});
echo 'str_rot13 ', '' === str_rot13(null) ? 'OK' : 'BAD', "\n";
$c = crypt(null, 'ab');
echo 'crypt ', is_string($c) && strlen($c) > 0 ? 'OK' : 'BAD', "\n";
$u = uniqid(null);
echo 'uniqid ', is_string($u) && strlen($u) >= 13 ? 'OK' : 'BAD', "\n";
echo 'gzcompress ', strlen(gzcompress(null)) === strlen(gzcompress('')) ? 'OK' : 'BAD', "\n";
