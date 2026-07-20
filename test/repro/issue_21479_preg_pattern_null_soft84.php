<?php
// #21479 — null $pattern soft-null DEP+WARN+false under PROFILE=8.4 (reverts #20226 TypeError)
error_reporting(E_ALL);
set_error_handler(static function (int $n, string $m): bool {
    if (E_DEPRECATED === $n) {
        echo "DEP\n";
        return true;
    }
    if (E_WARNING === $n) {
        echo "WARN\n";
        return true;
    }
    return false;
});
foreach ([
    'preg_match' => static fn () => preg_match(null, 'a'),
    'preg_match_all' => static fn () => preg_match_all(null, 'a'),
    'preg_split' => static fn () => preg_split(null, 'a'),
    'preg_grep' => static fn () => preg_grep(null, ['a']),
] as $name => $fn) {
    try {
        $r = $fn();
        echo $name, ' OK ', var_export($r, true), "\n";
    } catch (Throwable $e) {
        echo $name, ' ', get_class($e), "\n";
    }
}
echo "ALL_OK\n";
