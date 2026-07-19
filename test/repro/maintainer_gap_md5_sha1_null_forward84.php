<?php
/** Repro #21181 / formerly #19255 — md5/sha1(null) soft-null under PROFILE=8.4. */
error_reporting(E_ALL);
set_error_handler(static function (): bool {
    return true;
});
foreach (['md5', 'sha1'] as $fn) {
    try {
        $r = $fn(null);
        echo "$fn: digest=", substr((string) $r, 0, 8), "\n";
    } catch (TypeError $e) {
        echo "$fn: TypeError\n";
    }
}
echo "ok\n";
