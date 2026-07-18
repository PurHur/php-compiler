<?php
/** Repro for #20611 — ctype_*(null) must be false + E_DEPRECATED under PROFILE=8.4 (not TypeError). */
error_reporting(E_ALL);
$seen = [];
set_error_handler(static function (int $no, string $str) use (&$seen): bool {
    $seen[] = [$no, $str];
    return true;
});
try {
    $r = ctype_alpha(null);
    echo 'result=', var_export($r, true), "\n";
} catch (Throwable $e) {
    echo get_class($e), ': ', $e->getMessage(), "\n";
}
restore_error_handler();
$depr = 0;
foreach ($seen as [$no, $str]) {
    if (E_DEPRECATED === $no && str_contains($str, 'ctype_alpha(): Argument of type null will be interpreted as string in the future')) {
        $depr = 1;
    }
}
echo 'depr=', $depr, "\n";
