--TEST--
stdlib strcspn() null $characters soft-null DEP+coerce on 8.4 (#29394, ext/standard/string.c)
--ENV--
PHP_COMPILER_PROFILE=8.4
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
    $r = strcspn('abc', null);
    echo ($r === 3 ? 'OK' : 'BAD '.var_export($r, true)), "\n";
} catch (Throwable $e) {
    echo get_class($e), ': ', $e->getMessage(), "\n";
}
?>
--EXPECT--
DEP
OK
