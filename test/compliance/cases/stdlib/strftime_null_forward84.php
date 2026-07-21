<?php
// Guard #21582 — soft-null strftime()/gmstrftime() (DEP+false) under PHP_COMPILER_PROFILE=8.4
set_error_handler(static function (int $no, string $msg): bool {
    if (E_DEPRECATED === $no) {
        if (str_contains($msg, 'Passing null')) {
            echo "DEP_NULL\n";

            return true;
        }
        if (str_contains($msg, 'Function strftime() is deprecated')
            || str_contains($msg, 'Function gmstrftime() is deprecated')
        ) {
            echo "DEP_FN\n";

            return true;
        }
    }

    return false;
});
$cases = [
    'strftime_null' => [static fn () => strftime(null), false],
    'gmstrftime_null' => [static fn () => gmstrftime(null), false],
    'strftime_ok' => [static fn () => strftime('%Y', 946684800), '2000'],
];
foreach ($cases as $label => [$factory, $expect]) {
    try {
        $r = $factory();
        echo $label, ' ', ($r === $expect ? 'OK' : 'BAD '.var_export($r, true)), "\n";
    } catch (Throwable $e) {
        echo $label, ' ', get_class($e), "\n";
    }
}
