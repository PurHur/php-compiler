<?php
/**
 * Repro #21557 — hash_hmac(null $key)/hash_update(null) soft-null under PROFILE=8.4
 * (Zend 8.4.23: E_DEPRECATED + coerce; reverts #20175/#20195 TypeError).
 */
error_reporting(E_ALL);
$seen = 0;
set_error_handler(static function (int $no) use (&$seen): bool {
    if (E_DEPRECATED === $no) {
        $seen++;
        return true;
    }
    return false;
});
try {
    echo 'hmac ', hash_hmac('md5', 'd', null), PHP_EOL;
} catch (Throwable $e) {
    echo get_class($e), PHP_EOL;
}
try {
    $h = hash_init('md5');
    hash_update($h, null);
    echo 'upd ', hash_final($h), PHP_EOL;
} catch (Throwable $e) {
    echo get_class($e), PHP_EOL;
}
echo 'depr=', (int) ($seen >= 2), PHP_EOL;
