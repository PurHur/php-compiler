--TEST--
filter_has_var/filter_input null $type soft E_DEPRECATED JIT (#31486)
--FILE--
<?php
error_reporting(E_ALL);
set_error_handler(static function (int $errno, string $errstr): bool {
    $label = match ($errno) {
        E_DEPRECATED => 'DEPRECATED',
        E_WARNING => 'WARNING',
        default => (string) $errno,
    };
    echo $label, ': ', $errstr, "\n";

    return true;
});

foreach ([
    'filter_has_var' => static fn () => var_export(filter_has_var(null, 'x'), true),
    'filter_input' => static fn () => var_export(filter_input(null, 'x'), true),
] as $name => $fn) {
    echo "== $name ==\n";
    try {
        echo $fn(), "\n";
    } catch (Throwable $e) {
        echo get_class($e), ': ', $e->getMessage(), "\n";
    }
}
?>
--EXPECT--
== filter_has_var ==
DEPRECATED: filter_has_var(): Passing null to parameter #1 ($input_type) of type int is deprecated
false
== filter_input ==
DEPRECATED: filter_input(): Passing null to parameter #1 ($type) of type int is deprecated
NULL
