<?php
// Issue #21430 / #21516 — mb_strcut/mb_strimwidth/mb_detect_encoding soft-null under PROFILE=8.4
error_reporting(E_ALL & ~E_DEPRECATED);
foreach ([
    static fn () => mb_strcut(null, 0),
    static fn () => mb_strimwidth(null, 0, 5),
    static fn () => mb_detect_encoding(null),
] as $fn) {
    try {
        var_export($fn());
        echo "\n";
    } catch (Throwable $e) {
        echo get_class($e), "\n";
    }
}
