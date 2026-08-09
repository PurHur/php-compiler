--TEST--
stdlib htmlspecialchars()/htmlentities() null double_encode soft-DEP+coerce on 8.4 (#29445, ext/standard/html.c)
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
    var_export(htmlspecialchars('a', ENT_QUOTES, 'UTF-8', null));
    echo "\n";
    var_export(htmlspecialchars('&amp;', ENT_QUOTES, 'UTF-8', null));
    echo "\n";
    var_export(htmlentities('a', ENT_QUOTES, 'UTF-8', null));
    echo "\n";
} catch (Throwable $e) {
    echo get_class($e), ': ', $e->getMessage(), "\n";
}
?>
--EXPECT--
DEP:htmlspecialchars(): Passing null to parameter #4 ($double_encode) of type bool is deprecated
'a'
DEP:htmlspecialchars(): Passing null to parameter #4 ($double_encode) of type bool is deprecated
'&amp;'
DEP:htmlentities(): Passing null to parameter #4 ($double_encode) of type bool is deprecated
'a'
