--TEST--
filter_var_array null $options soft E_DEPRECATED + unknown filter JIT (#31490)
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

try {
    var_export(filter_var_array(['a' => '1'], null));
    echo "\n";
} catch (Throwable $e) {
    echo get_class($e), ': ', $e->getMessage(), "\n";
}
?>
--EXPECT--
DEPRECATED: filter_var_array(): Passing null to parameter #2 ($options) of type array|int is deprecated
WARNING: filter_var_array(): Unknown filter with ID 0
false
