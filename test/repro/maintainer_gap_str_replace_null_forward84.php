<?php
// #21198 — 8.4 forward profile soft-null on replace $subject
set_error_handler(static function (int $no, string $msg): bool {
    if (E_DEPRECATED === $no) {
        echo "DEP\n";

        return true;
    }

    return false;
});

$checks = [
    'str_replace' => static fn () => str_replace('a', 'b', null),
    'str_ireplace' => static fn () => str_ireplace('a', 'b', null),
    'preg_replace' => static fn () => preg_replace('//', 'x', null),
];
foreach ($checks as $label => $factory) {
    try {
        $r = $factory();
        echo $label, ' OK ', var_export($r, true), "\n";
    } catch (Throwable $e) {
        echo $label, ': ', get_class($e), ':', $e->getMessage(), "\n";
        exit(1);
    }
}
echo "ok\n";
