--TEST--
JIT: linkinfo() via libc lstat(2) st_dev (#6083)
--FILE--
<?php
$base = 'test/compliance/cases/stdlib/is_link_fixture';
$link = $base . '/link';
$lstat = lstat($link);
$info = linkinfo($link);
echo ($lstat !== false && $info !== false && $info === $lstat['dev']) ? 'ok' : 'fail', "\n";
--EXPECT--
ok
