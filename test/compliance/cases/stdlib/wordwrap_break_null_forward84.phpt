--TEST--
stdlib wordwrap() null $break soft-DEP then empty ValueError on 8.4 (#29720, ext/standard/string.c)
--ENV--
PHP_COMPILER_PROFILE=8.4
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
    var_export(wordwrap('hi there', 75, null));
    echo "\n";
} catch (Throwable $e) {
    echo get_class($e), ': ', $e->getMessage(), "\n";
}
?>
--EXPECT--
DEP:wordwrap(): Passing null to parameter #3 ($break) of type string is deprecated
ValueError: wordwrap(): Argument #3 ($break) must not be empty
