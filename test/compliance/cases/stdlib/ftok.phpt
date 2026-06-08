--TEST--
stdlib ftok() returns System V IPC key for existing file (ext/standard/basic_functions.c, #6296)
--FILE--
<?php
if (!function_exists('ftok')) {
    echo "missing\n";
    exit(1);
}
$path = tempnam(sys_get_temp_dir(), 'ftok');
$key = ftok($path, 't');
echo is_int($key) && $key !== -1 ? "ok\n" : "bad\n";
$key2 = ftok($path, 't');
echo $key === $key2 ? "stable\n" : "bad\n";
@unlink($path);
$missing = @ftok('/nonexistent/ftok/path', 'x');
echo $missing === -1 ? "missing\n" : "bad\n";
--EXPECT--
ok
stable
missing
