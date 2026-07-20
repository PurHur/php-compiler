--TEST--
stdlib filestat path builtins — int path TypeError on 8.4 forward profile (#5122, ext/standard/filestat.c)
--ENV--
PHP_COMPILER_PROFILE=8.4
--FILE--
<?php
foreach (['is_file', 'is_dir', 'is_link', 'is_readable', 'is_writable', 'file_exists'] as $fn) {
    try {
        $fn(1);
        echo $fn, ": no error\n";
    } catch (TypeError $e) {
        echo $fn, ': ', $e->getMessage(), "\n";
    }
}
--EXPECT--
is_file: is_file(): Argument #1 ($filename) must be of type string, int given
is_dir: is_dir(): Argument #1 ($filename) must be of type string, int given
is_link: is_link(): Argument #1 ($filename) must be of type string, int given
is_readable: is_readable(): Argument #1 ($filename) must be of type string, int given
is_writable: is_writable(): Argument #1 ($filename) must be of type string, int given
file_exists: file_exists(): Argument #1 ($filename) must be of type string, int given
