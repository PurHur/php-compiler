<?php
/**
 * Issue #21319 — hash_pbkdf2()/hash_hkdf() null soft-null under PROFILE=8.4
 * (Zend DEP+coerce; hkdf empty key → ValueError, not TypeError).
 */
error_reporting(E_ALL);
$seen = 0;
set_error_handler(static function (int $no) use (&$seen): bool {
    if (E_DEPRECATED === $no) {
        $seen++;
    }

    return true;
});

try {
    $r = hash_pbkdf2('sha256', null, 'salt', 1);
    echo 'pbkdf2:OK ', var_export($r === hash_pbkdf2('sha256', '', 'salt', 1), true), "\n";
} catch (Throwable $e) {
    echo 'pbkdf2:', get_class($e), "\n";
}

try {
    hash_hkdf('sha256', null);
    echo "hkdf:OK\n";
} catch (ValueError $e) {
    echo "hkdf:ValueError\n";
} catch (TypeError $e) {
    echo "hkdf:TypeError\n";
}

restore_error_handler();
echo 'depr=', (int) ($seen >= 1), "\n";
