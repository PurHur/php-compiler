--TEST--
stdlib lstat()
--FILE--
<?php
$base = 'test/compliance/cases/stdlib/is_link_fixture';
$link = $base . '/link';
$stat = stat($link);
$lstat = lstat($link);
echo ($stat !== false && $lstat !== false && $stat['size'] !== $lstat['size']) ? 'symlink' : 'fail', "\n";
echo ($lstat !== false) ? $lstat['size'] : 'fail', "\n";
--EXPECT--
symlink
10
