<?php
// Repro #21223 — json_decode/unserialize(null) soft-null under PROFILE=8.4 (Zend DEP+coerce).
// parse_str(null) is Z_PARAM_STR TypeError (#21380), not soft-null.
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
        $v = $fn();
        echo $n, '=', var_export($v, true), "\n";
    } catch (Throwable $e) {
        echo $n, '=', get_class($e), "\n";
    }
}
restore_error_handler();
echo 'depr=', (int) (count($seen) >= 2), "\n";
