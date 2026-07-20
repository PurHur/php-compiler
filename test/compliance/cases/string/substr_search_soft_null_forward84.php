<?php
// Guard #21189 — soft-null (DEP+coerce) under PHP_COMPILER_PROFILE=8.4
error_reporting(E_ALL);
$seen = [];
set_error_handler(static function (int $no, string $str) use (&$seen): bool {
    $seen[] = [$no, $str];

    return true;
});
$cases = [
    'substr' => [static fn () => substr(null, 0), 'substr(): Passing null to parameter #1 ($string) of type string is deprecated', static fn ($r) => '' === $r],
    'strpos' => [static fn () => strpos(null, 'a'), 'strpos(): Passing null to parameter #1 ($haystack) of type string is deprecated', static fn ($r) => false === $r],
    'strstr' => [static fn () => strstr(null, 'a'), 'strstr(): Passing null to parameter #1 ($haystack) of type string is deprecated', static fn ($r) => false === $r],
    'explode' => [static fn () => explode(',', null), 'explode(): Passing null to parameter #2 ($string) of type string is deprecated', static fn ($r) => is_array($r) && ['' ] === $r],
    'str_replace' => [static fn () => str_replace('a', 'b', null), 'str_replace(): Passing null to parameter #3 ($subject) of type array|string is deprecated', static fn ($r) => '' === $r],
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
