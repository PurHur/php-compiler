--TEST--
mb_ereg/mb_eregi(null) soft-DEP then empty ValueError on 8.4 JIT (#30067, php_mbregex.c)
--ENV--
PHP_COMPILER_PROFILE=8.4
--JIT--
--FILE--
<?php
set_error_handler(static function (int $no, string $msg): bool {
    if (E_DEPRECATED === $no) {
        echo 'DEP:', $msg, "\n";
        return true;
    }
    return false;
});
try {
    var_export(mb_ereg(null, 'a'));
    echo "\n";
} catch (Throwable $e) {
    echo get_class($e), ': ', $e->getMessage(), "\n";
}
try {
    var_export(mb_eregi(null, 'a'));
    echo "\n";
} catch (Throwable $e) {
    echo get_class($e), ': ', $e->getMessage(), "\n";
}
?>
--EXPECT--
DEP:mb_ereg(): Passing null to parameter #1 ($pattern) of type string is deprecated
ValueError: mb_ereg(): Argument #1 ($pattern) must not be empty
DEP:mb_eregi(): Passing null to parameter #1 ($pattern) of type string is deprecated
ValueError: mb_eregi(): Argument #1 ($pattern) must not be empty
