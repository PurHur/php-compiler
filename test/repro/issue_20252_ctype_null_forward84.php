<?php
/** Repro for #20252 — superseded by #20611: ctype stubs are mixed, null is E_DEPRECATED+false. */
error_reporting(E_ALL);
$seen = [];
set_error_handler(static function (int $no, string $str) use (&$seen): bool {
    $seen[] = [$no, $str];
    return true;
});
foreach (['ctype_alnum', 'ctype_digit', 'ctype_space', 'ctype_alpha'] as $f) {
    try {
        $r = $f(null);
        echo "$f result=", var_export($r, true), "\n";
    } catch (Throwable $e) {
        echo "$f ", get_class($e), "\n";
    }
}
restore_error_handler();
$depr = 0;
foreach ($seen as [$no, $str]) {
    if (E_DEPRECATED === $no && str_contains($str, 'will be interpreted as string')) {
        $depr = 1;
    }
}
echo 'depr=', $depr, "\n";
