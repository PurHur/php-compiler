<?php
// #21478 — pack/unpack null $format soft-null under PROFILE=8.4 (reverts #20241 TypeError)
error_reporting(E_ALL);
set_error_handler(static function (int $n, string $m): bool {
    if (E_DEPRECATED === $n && str_contains($m, 'Passing null')) {
        echo "DEP\n";
        return true;
    }
    return false;
});
foreach ([
    'pack' => static fn () => pack(null),
    'unpack' => static fn () => unpack(null, 'x'),
] as $name => $fn) {
    try {
        $r = $fn();
        echo $name, ' OK ', var_export($r, true), "\n";
    } catch (Throwable $e) {
        echo $name, ' ', get_class($e), "\n";
    }
}
echo "ALL_OK\n";
