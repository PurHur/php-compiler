--TEST--
stdlib wordwrap() null cut_long_words soft-DEP+coerce on 8.4 (#29354, ext/standard/string.c)
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
    var_export(wordwrap('abcd', 2, "\n", null));
    echo "\n";
} catch (Throwable $e) {
    echo get_class($e), ': ', $e->getMessage(), "\n";
}
?>
--EXPECT--
DEP:wordwrap(): Passing null to parameter #4 ($cut_long_words) of type bool is deprecated
'abcd'
