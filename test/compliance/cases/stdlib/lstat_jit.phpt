--TEST--
stdlib lstat() JIT
--FILE--
<?php
$base = 'test/compliance/cases/stdlib/is_link_fixture';
$link = $base . '/link';
$stat = stat($link);
$lstat = lstat($link);
echo ($stat['size'] !== $lstat['size']) ? 'symlink' : 'fail', "\n";
echo $lstat['size'], "\n";
--EXPECT--
symlink
10
