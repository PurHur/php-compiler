<?php
/** AOT-only openssl_digest null soft (#21517). */
$deps = [];
set_error_handler(static function (int $n, string $m) use (&$deps): bool {
    if (E_DEPRECATED === $n) {
        $deps[] = $m;
        echo "DEP\n";
    }
    return true;
});
$empty = openssl_digest('', 'sha256');
$null = openssl_digest(null, 'sha256');
if (is_string($null) && $empty === $null && isset($deps[0])) {
    echo "openssl_digest OK\n";
    exit(0);
}
echo "FAIL\n";
exit(1);
