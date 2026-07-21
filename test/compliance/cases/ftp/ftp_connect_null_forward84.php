<?php
// Guard #21757 / #21868 — ftp_connect()/ftp_ssl_connect(null) soft-null DEP+false under PROFILE=8.4
set_error_handler(static function (int $no, string $msg): bool {
    if (E_DEPRECATED === $no && str_contains($msg, 'Passing null')) {
        echo "DEP_NULL\n";

        return true;
    }

    return false;
});

foreach (['ftp_connect', 'ftp_ssl_connect'] as $fn) {
    try {
        $r = @$fn(null);
        echo $fn, ' ', var_export($r, true), " COERCED\n";
    } catch (TypeError $e) {
        echo $fn, ' TypeError: ', $e->getMessage(), "\n";
    }
}
