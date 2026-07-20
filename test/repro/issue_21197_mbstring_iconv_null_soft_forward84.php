<?php
// Repro #21197 — mbstring/iconv soft-null under PHP_COMPILER_PROFILE=8.4 (Zend DEP+coerce)
set_error_handler(static function (int $no, string $msg): bool {
    if (E_DEPRECATED === $no) {
        echo "DEP\n";

        return true;
    }

    return false;
});
$cases = [
    ['mb_strlen', [null]],
    ['mb_substr', [null, 0]],
    ['mb_strpos', [null, 'a']],
    ['iconv', ['UTF-8', 'UTF-8', null]],
    ['iconv_strlen', [null]],
];
foreach ($cases as [$f, $a]) {
    try {
        $r = $f(...$a);
        echo $f, " OK\n";
    } catch (Throwable $e) {
        echo $f, ' ', get_class($e), "\n";
    }
}
