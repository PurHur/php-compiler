<?php
/** Repro #21181 — md5/sha1/crc32/bin2hex/hash(null) DEP+coerce under PROFILE=8.4 (not TypeError). */
error_reporting(E_ALL);
$seen = 0;
set_error_handler(static function (int $no) use (&$seen): bool {
    if (E_DEPRECATED === $no) {
        $seen++;
    }
    return true;
});
foreach (['md5', 'sha1', 'crc32', 'bin2hex'] as $f) {
    try {
        $r = $f(null);
        echo $f, ' OK=', var_export($r, true), "\n";
    } catch (Throwable $e) {
        echo $f, ' ', get_class($e), "\n";
    }
}
try {
    $r = hash('md5', null);
    echo 'hash OK=', var_export($r, true), "\n";
} catch (Throwable $e) {
    echo 'hash ', get_class($e), "\n";
}
restore_error_handler();
echo 'depr=', (int) ($seen >= 5), "\n";
