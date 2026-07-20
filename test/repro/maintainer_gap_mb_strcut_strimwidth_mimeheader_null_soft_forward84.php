<?php
// Repro #21430 — mb_strcut/mb_strimwidth/mb_encode_mimeheader soft-null under PROFILE=8.4
error_reporting(E_ALL);
set_error_handler(static function (int $no, string $msg): bool {
    if (E_DEPRECATED === $no) {
        echo "DEP\n";

        return true;
    }

    return false;
});
$ok = true;
foreach ([
    ['mb_strcut', static fn () => mb_strcut(null, 0, 1), ''],
    ['mb_strimwidth', static fn () => mb_strimwidth(null, 0, 5, '...'), ''],
    ['mb_encode_mimeheader', static fn () => mb_encode_mimeheader(null), ''],
] as [$name, $fn, $expect]) {
    try {
        $r = $fn();
        if ($r !== $expect) {
            echo $name, ' BAD ', var_export($r, true), "\n";
            $ok = false;
        } else {
            echo $name, " OK\n";
        }
    } catch (Throwable $e) {
        echo $name, ' ', get_class($e), "\n";
        $ok = false;
    }
}
echo $ok ? "ALL_OK\n" : "FAIL\n";
