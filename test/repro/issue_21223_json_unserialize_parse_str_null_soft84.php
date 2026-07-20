<?php
// Repro #21223 — json_decode/unserialize/parse_str(null) soft-null under PROFILE=8.4 (Zend DEP+coerce).
error_reporting(E_ALL);
$seen = [];
set_error_handler(static function (int $no, string $str) use (&$seen): bool {
    if (E_DEPRECATED === $no) {
        $seen[] = $str;
    }
    return true;
});
foreach ([
    'json_decode' => static fn () => json_decode(null),
    'unserialize' => static fn () => unserialize(null),
    'parse_str' => static function () {
        $x = null;
        parse_str(null, $x);
        return $x;
    },
] as $n => $fn) {
    try {
        echo $n, '=', var_export($fn(), true), "\n";
    } catch (Throwable $e) {
        echo $n, '=', get_class($e), "\n";
    }
}
restore_error_handler();
echo 'depr=', (int) (count($seen) >= 3), "\n";
