<?php
// Repro #21282 — mb_strtolower/mb_convert_encoding/mb_substr_count soft-null under PROFILE=8.4
set_error_handler(static function (int $no, string $msg): bool {
    if (E_DEPRECATED === $no) {
        echo "DEP\n";

        return true;
    }

    return false;
});
$cases = [
    ['mb_strtolower', [null]],
    ['mb_convert_encoding', [null, 'UTF-8', 'UTF-8']],
    ['mb_substr_count', [null, 'a']],
];
foreach ($cases as [$f, $a]) {
    try {
        $r = $f(...$a);
        echo $f, ' OK ', var_export($r, true), "\n";
    } catch (Throwable $e) {
        echo $f, ' ', get_class($e), "\n";
    }
}
