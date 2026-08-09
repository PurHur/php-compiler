<?php
// Guard #21515 — soft-null (DEP+coerce) under PHP_COMPILER_PROFILE=8.4 (reverts #20164 TypeError)
set_error_handler(static function (int $no, string $msg): bool {
    if (E_DEPRECATED === $no) {
        echo "DEP\n";

        return true;
    }

    return false;
});
$cases = [
    'haystack' => [static fn () => substr_compare(null, 'a', 0), -1],
    'needle' => [static fn () => substr_compare('abc', null, 0), 1],
    // #29504 — Z_PARAM_LONG $offset soft-null (peer substr_count #21657).
    'offset' => [static fn () => substr_compare('abc', 'b', null), -1],
];
foreach ($cases as $label => [$factory, $expect]) {
    try {
        $r = $factory();
        echo $label, ' ', ($r === $expect ? 'OK' : 'BAD '.var_export($r, true)), "\n";
    } catch (Throwable $e) {
        echo $label, ' ', get_class($e), "\n";
    }
}
