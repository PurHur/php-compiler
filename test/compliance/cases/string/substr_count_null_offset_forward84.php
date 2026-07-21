<?php
// Guard #21657 — soft-null $offset (DEP+coerce→0) under PHP_COMPILER_PROFILE=8.4
set_error_handler(static function (int $no, string $msg): bool {
    if (E_DEPRECATED === $no) {
        echo "DEP\n";

        return true;
    }

    return false;
});
$cases = [
    'offset' => [static fn () => substr_count('aaa', 'a', null), 3],
    'length_control' => [static fn () => substr_count('aaaa', 'a', 0, null), 4],
    'offset_one' => [static fn () => substr_count('aaa', 'a', 1), 2],
];
foreach ($cases as $label => [$factory, $expect]) {
    try {
        $r = $factory();
        echo $label, ' ', ($r === $expect ? 'OK' : 'BAD '.var_export($r, true)), "\n";
    } catch (Throwable $e) {
        echo $label, ' ', get_class($e), "\n";
    }
}
