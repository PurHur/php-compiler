--TEST--
stdlib pfsockopen(null) soft Deprecated+Warning+false (#30393, ext/standard/fsock.c)
--FILE--
<?php
error_reporting(E_ALL);
set_error_handler(static function (int $no, string $msg): bool {
    if (E_DEPRECATED === $no) {
        echo "DEP\n";
        return true;
    }
    if (E_WARNING === $no) {
        echo (str_contains($msg, 'Unable to connect') && str_contains($msg, 'Failed to parse address')
            ? "WARN\n" : "WARN_OTHER\n");
        return true;
    }
    return false;
});
try {
    var_export(pfsockopen(null));
    echo "\n";
} catch (Throwable $e) {
    echo get_class($e), ':', $e->getMessage(), "\n";
}
--EXPECT--
DEP
WARN
false
