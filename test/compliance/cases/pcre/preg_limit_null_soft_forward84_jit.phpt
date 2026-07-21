--TEST--
PCRE preg_* null $limit soft-null DEP+coerce on 8.4 JIT (#21655)
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
    var_export(preg_replace('/\w/', 'x', 'ab', null));
    echo "\n";
} catch (Throwable $e) {
    echo get_class($e), "\n";
}
try {
    var_export(count(preg_split('//u', 'ab', null)));
    echo "\n";
} catch (Throwable $e) {
    echo get_class($e), "\n";
}
try {
    var_export(preg_filter('/\w/', 'x', 'ab', null));
    echo "\n";
} catch (Throwable $e) {
    echo get_class($e), "\n";
}
echo preg_replace('/\w/', 'x', 'ab', 1), "\n";
echo preg_replace('/\w/', 'x', 'ab', -1), "\n";
?>
--EXPECT--
DEP
'ab'
DEP
4
DEP
NULL
xb
xx
