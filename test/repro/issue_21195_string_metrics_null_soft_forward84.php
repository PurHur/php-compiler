<?php
// Repro #21195 — levenshtein/similar_text/strcoll/strcspn/strspn/strtok soft-null under PROFILE=8.4
error_reporting(E_ALL);
set_error_handler(static function (int $no, string $str): bool {
    if (E_DEPRECATED === $no) {
        echo "DEP\n";
    }

    return true;
});
$cases = [
    ['levenshtein', [null, ''], 0],
    ['similar_text', [null, ''], 0],
    ['strcoll', [null, ''], 0],
    ['strcspn', [null, 'a'], 0],
    ['strspn', [null, 'a'], 0],
    ['strtok', [null, ' '], false],
];
foreach ($cases as [$f, $a, $expect]) {
    try {
        $r = $f(...$a);
        echo $f, ' ', ($r === $expect ? 'OK' : 'BAD '.var_export($r, true)), "\n";
    } catch (Throwable $e) {
        echo $f, ' ', get_class($e), "\n";
    }
}
