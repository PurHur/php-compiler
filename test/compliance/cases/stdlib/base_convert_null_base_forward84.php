<?php
// Guard #21704 — base_convert() null $from_base / $to_base: DEP+coerce→0 then ValueError (php-src base_convert.c)
set_error_handler(static function (int $no, string $msg): bool {
    if (E_DEPRECATED === $no && str_contains($msg, 'Passing null')) {
        echo "DEP\n";

        return true;
    }

    return false;
});
$cases = [
    'null_from_base' => [static function () {
        try {
            base_convert('10', null, 16);

            return 'NO_THROW';
        } catch (ValueError $e) {
            return str_contains($e->getMessage(), 'Argument #2 ($from_base) must be between 2 and 36')
                ? 'ValueError'
                : 'OTHER';
        }
    }, 'ValueError'],
    'null_to_base' => [static function () {
        try {
            base_convert('10', 10, null);

            return 'NO_THROW';
        } catch (ValueError $e) {
            return str_contains($e->getMessage(), 'Argument #3 ($to_base) must be between 2 and 36')
                ? 'ValueError'
                : 'OTHER';
        }
    }, 'ValueError'],
    'ok' => [static fn () => base_convert('10', 10, 16), 'a'],
];
foreach ($cases as $label => [$factory, $expect]) {
    try {
        $r = $factory();
        echo $label, ' ', ($r === $expect ? 'OK' : 'BAD '.var_export($r, true)), "\n";
    } catch (Throwable $e) {
        echo $label, ' ', get_class($e), "\n";
    }
}
