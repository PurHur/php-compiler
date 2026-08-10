--TEST--
stdlib preg_replace() null $replacement DEP type array|string (#29722, ext/pcre/php_pcre.c)
--ENV--
PHP_COMPILER_PROFILE=8.4
--FILE--
<?php
error_reporting(E_ALL);
set_error_handler(static function (int $errno, string $errstr): bool {
    if (E_DEPRECATED === $errno) {
        echo 'DEP:', $errstr, "\n";

        return true;
    }

    return false;
});
var_export(preg_replace('/a/', null, 'a'));
echo "\n";
?>
--EXPECT--
DEP:preg_replace(): Passing null to parameter #2 ($replacement) of type array|string is deprecated
''
