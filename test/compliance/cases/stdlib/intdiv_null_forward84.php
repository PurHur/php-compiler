<?php
// Guard #21593 — soft-null intdiv() int args (DEP+coerce→0) under PHP_COMPILER_PROFILE=8.4
set_error_handler(static function (int $no, string $msg): bool {
    if (E_DEPRECATED === $no && str_contains($msg, 'Passing null')) {
        echo "DEP\n";

        return true;
    }

    return false;
});
$cases = [
    'null_num1' => [static fn () => intdiv(null, 2), 0],
    'null_num2' => [static function () {
        try {
            return intdiv(10, null);
        } catch (DivisionByZeroError $e) {
            return 'div0';
        }
    }, 'div0'],
    'ok' => [static fn () => intdiv(10, 2), 5],
    'non_numeric' => [static function () {
        try {
            intdiv('x', 2);

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
