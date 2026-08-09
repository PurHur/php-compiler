--TEST--
Language: Closure dynprop Error getFile()/getLine() match user assignment (#29457)
--ENV--
PHP_COMPILER_PROFILE=8.4
--FILE--
<?php
try {
    $f = function () {};
    $f->a = 1;
} catch (Error $e) {
    echo $e->getFile() !== '' ? "file_ok\n" : "file_bad\n";
    echo $e->getLine() >= 4 ? "line_ok\n" : "line_bad\n";
    echo str_contains($e->getFile(), 'ExceptionSupport') ? "site_bad\n" : "site_ok\n";
    echo $e->getMessage(), "\n";
}
--EXPECT--
file_ok
line_ok
site_ok
Cannot create dynamic property Closure::$a
