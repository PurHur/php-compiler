<?php
// Issue #20225 — mb_strcut/mb_strimwidth/mb_detect_encoding null TypeError under PROFILE=8.4
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
