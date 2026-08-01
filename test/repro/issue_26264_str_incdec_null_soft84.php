<?php
/** Repro for #26264 — str_increment/str_decrement(null) soft-null + ValueError empty (not TypeError). */
error_reporting(E_ALL);
$depr = 0;
set_error_handler(static function (int $errno, string $errstr) use (&$depr): bool {
    if (E_DEPRECATED === $errno && str_contains($errstr, 'Passing null')) {
        ++$depr;
        return true;
    }
    return false;
});
foreach (['str_increment', 'str_decrement'] as $f) {
    $before = $depr;
    try {
        $f(null);
        echo $f, " COERCED\n";
    } catch (Throwable $e) {
        echo $f, ' ', get_class($e), ' empty=', (int) str_contains($e->getMessage(), 'must not be empty'),
            ' depr=', $depr - $before, "\n";
    }
}
echo "inc_a=", str_increment('a'), "\n";
