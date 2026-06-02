--TEST--
AOT: link() creates hard link via libc link(2) (issue #3589)
--SKIPIF--
<?php if (!function_exists('link')) { die('skip hard links unavailable'); } ?>
--FILE--
<?php
echo function_exists('link') ? '1' : '0', "\n";
$base = 'test/compliance/cases/stdlib/link_fixture';
$src = $base . '/src.txt';
$dst = $base . '/hardlink.txt';
$_u0 = @unlink($dst);
$_u1 = @unlink($src);
$n = file_put_contents($src, 'x');
$ok = link($src, $dst);
if ($ok) {
    echo file_get_contents($dst), "\n";
} else {
    echo 'fail', "\n";
}
$_u2 = @unlink($dst);
$_u3 = @unlink($src);
--EXPECT--
1
x
