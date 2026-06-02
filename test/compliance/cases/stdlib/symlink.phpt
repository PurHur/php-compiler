--TEST--
stdlib symlink() — symbolic link creation (issue #3227)
--SKIPIF--
<?php if (!function_exists('symlink')) { die('skip symlinks unavailable'); } ?>
--FILE--
<?php
echo function_exists('symlink') ? '1' : '0', "\n";
$base = 'test/compliance/cases/stdlib/symlink_fixture';
$src = $base . '/target.txt';
$link = $base . '/sym';
$_u0 = @unlink($link);
$_u1 = @unlink($src);
$n = file_put_contents($src, 'data');
$ok = symlink('target.txt', $link);
if ($ok) {
    echo readlink($link), "\n";
    echo file_get_contents($link), "\n";
} else {
    echo 'fail', "\n";
}
$_u2 = @unlink($link);
$_u3 = @unlink($src);
@unlink($base . '/nope.txt');
$bad = symlink('/no/such/phpc-symlink-src', $base . '/nope.txt');
if ($bad) {
    echo 'bad', "\n";
} else {
    echo 'nogone', "\n";
}
--EXPECT--
1
target.txt
data
bad
