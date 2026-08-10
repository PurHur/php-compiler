--TEST--
stdlib substr_compare(null $case_insensitive) soft-null DEP+coerce (#29756, ext/standard/string.c Z_PARAM_BOOL)
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
    $r = substr_compare('abc', 'ab', 0, 2, null);
    echo (0 === $r ? 'OK' : 'BAD '.var_export($r, true)), "\n";
} catch (Throwable $e) {
    echo get_class($e), ':', $e->getMessage(), "\n";
}
?>
--EXPECT--
DEP
OK
