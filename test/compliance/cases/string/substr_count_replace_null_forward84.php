<?php
// Guard #21196 — soft-null (DEP+coerce) under PHP_COMPILER_PROFILE=8.4
error_reporting(E_ALL);
$seen = [];
set_error_handler(static function (int $no, string $str) use (&$seen): bool {
    $seen[] = [$no, $str];

    return true;
});
$cases = [
    'substr_count' => [
        static fn () => substr_count(null, 'a'),
        'substr_count(): Passing null to parameter #1 ($haystack) of type string is deprecated',
        static fn ($r) => 0 === $r,
    ],
    'substr_replace' => [
        static fn () => substr_replace(null, 'x', 0),
        'substr_replace(): Passing null to parameter #1 ($string) of type array|string is deprecated',
        static fn ($r) => 'x' === $r,
    ],
];
foreach ($cases as $label => [$factory, $msg, $ok]) {
    $before = count($seen);
    try {
        $r = $factory();
        $depr = 0;
        for ($i = $before; $i < count($seen); ++$i) {
            [$no, $str] = $seen[$i];
            if (E_DEPRECATED === $no && $str === $msg) {
                $depr = 1;
            }
        }
        echo $label, ': OK=', $ok($r) ? '1' : '0', ' depr=', $depr, "\n";
    } catch (TypeError $e) {
        echo $label, ': ', $e->getMessage(), "\n";
    }
}
restore_error_handler();
