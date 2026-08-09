--TEST--
stdlib substr_compare(null $offset) soft-null DEP+coerce (#29504, ext/standard/string.c Z_PARAM_LONG)
--FILE--
<?php
set_error_handler(static function (int $no, string $msg): bool {
    if (E_DEPRECATED === $no) {
        echo "DEP\n";

        return true;
    }

    return false;
});
try {
    $r = substr_compare('abc', 'b', null);
    echo ($r === -1 ? 'OK' : 'BAD '.var_export($r, true)), "\n";
} catch (Throwable $e) {
    echo get_class($e), ':', $e->getMessage(), "\n";
}
?>
--EXPECT--
DEP
OK
