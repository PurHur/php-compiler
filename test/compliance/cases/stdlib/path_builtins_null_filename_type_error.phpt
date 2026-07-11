--TEST--
stdlib path builtins null filename TypeError (ext/standard/filestat.c, #12640)
--FILE--
<?php
foreach (['unlink', 'is_file', 'file_exists'] as $fn) {
    try {
        $fn(null);
    } catch (Throwable $e) {
        echo $fn, ': ', get_class($e), "\n";
    }
}
--EXPECT--
unlink: TypeError
is_file: TypeError
file_exists: TypeError
