<?php
// Guard #21594 — soft-null checkdate() int args (DEP+coerce→0) under PHP_COMPILER_PROFILE=8.4
set_error_handler(static function (int $no, string $msg): bool {
    if (E_DEPRECATED === $no && str_contains($msg, 'Passing null')) {
        echo "DEP\n";

        return true;
    }

    return false;
});
$cases = [
    'null_month' => [static fn () => checkdate(null, 1, 2000), false],
    'null_day' => [static fn () => checkdate(2, null, 2020), false],
    'leap_ok' => [static fn () => checkdate(2, 29, 2020), true],
    'non_numeric' => [static function () {
        try {
            checkdate('feb', 29, 2020);

            return 'COERCE';
        } catch (TypeError $e) {
            return str_contains($e->getMessage(), 'must be of type int') ? 'TypeError' : 'OTHER';
        }
    }, 'TypeError'],
];
foreach ($cases as $label => [$factory, $expect]) {
    try {
        $r = $factory();
        echo $label, ' ', ($r === $expect ? 'OK' : 'BAD '.var_export($r, true)), "\n";
    } catch (Throwable $e) {
        echo $label, ' ', get_class($e), "\n";
    }
}
