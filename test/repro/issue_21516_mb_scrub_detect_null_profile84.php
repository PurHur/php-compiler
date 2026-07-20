<?php
// Issue #21516 — mb_scrub/mb_detect_encoding soft-null DEP+coerce under PROFILE=8.4
set_error_handler(static function (int $n, string $m): bool {
    if (E_DEPRECATED === $n) {
        echo "DEP\n";

        return true;
    }

    return false;
});
foreach ([
    'mb_scrub' => static fn () => mb_scrub(null),
    'mb_detect_encoding' => static fn () => mb_detect_encoding(null),
] as $n => $fn) {
    try {
        echo $n, ' OK ', var_export($fn(), true), "\n";
    } catch (Throwable $e) {
        echo $n, ' ', get_class($e), "\n";
    }
}
