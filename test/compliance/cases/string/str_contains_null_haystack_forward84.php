<?php
// Guard #21187 — null haystack soft-null (DEP+false) under PHP_COMPILER_PROFILE=8.4 (reverts #19273 TypeError)
error_reporting(E_ALL);
$seen = [];
set_error_handler(static function (int $no, string $str) use (&$seen): bool {
    $seen[] = [$no, $str];

    return true;
});
foreach ([
    'str_contains' => static fn () => str_contains(null, 'x'),
    'str_starts_with' => static fn () => str_starts_with(null, 'x'),
    'str_ends_with' => static fn () => str_ends_with(null, 'x'),
] as $label => $factory) {
    $before = count($seen);
    try {
        $r = $factory();
        $depr = 0;
        for ($i = $before; $i < count($seen); ++$i) {
            [$no, $str] = $seen[$i];
            if (E_DEPRECATED === $no
                && str_contains($str, $label.'(): Passing null to parameter #1 ($haystack) of type string is deprecated')
            ) {
                $depr = 1;
            }
        }
        echo $label, ': OK=', var_export($r, true), ' depr=', $depr, "\n";
    } catch (TypeError $e) {
        echo $label, ': ', $e->getMessage(), "\n";
    }
}
restore_error_handler();
