--TEST--
stdlib flock() null $operation — soft DEP then ValueError (#31462, ext/standard/file.c)
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
$fp = fopen('php://memory', 'r+');
try {
    flock($fp, null);
    echo "no_throw\n";
} catch (Throwable $e) {
    echo get_class($e), ': ', $e->getMessage(), "\n";
} finally {
    fclose($fp);
}
--EXPECT--
DEPRECATED: flock(): Passing null to parameter #2 ($operation) of type int is deprecated
ValueError: flock(): Argument #2 ($operation) must be one of LOCK_SH, LOCK_EX, or LOCK_UN
