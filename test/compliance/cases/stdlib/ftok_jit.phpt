--TEST--
stdlib ftok() JIT — System V IPC key for existing file (#6296)
--FILE--
<?php
$path = tempnam(sys_get_temp_dir(), 'ftok_jit');
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
