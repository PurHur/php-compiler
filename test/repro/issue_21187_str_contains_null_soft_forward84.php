<?php
// Repro #21187 — str_contains/starts_with/ends_with null haystack soft-null under PROFILE=8.4
set_error_handler(static function (int $n, string $m): bool {
    if (E_DEPRECATED === $n) {
        echo "DEP\n";
    }

    return true;
});
foreach (['str_contains', 'str_starts_with', 'str_ends_with'] as $f) {
    try {
        $r = $f(null, 'a');
        echo $f, ' OK=', var_export($r, true), "\n";
    } catch (Throwable $e) {
        echo $f, ' ', get_class($e), "\n";
    }
}
