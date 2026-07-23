--TEST--
pcre preg_grep() null array — TypeError (#22679, ext/pcre/php_pcre.c)
--FILE--
<?php
error_reporting(E_ALL);
try {
    var_export(preg_grep('/a/', null));
    echo " (uncaught)\n";
} catch (Throwable $e) {
    echo get_class($e), ':', $e->getMessage(), "\n";
}
?>
--EXPECT--
TypeError:preg_grep(): Argument #2 ($array) must be of type array, null given
