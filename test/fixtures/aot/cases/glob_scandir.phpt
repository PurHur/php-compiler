--TEST--
AOT: glob() and scandir() via libc (issue #665)
--FILE--
<?php
$dir = 'test/compliance/cases/stdlib/glob_scandir_fixture';
$matches = glob($dir . '/*.php');
echo count($matches), "\n";
echo count(glob($dir . '/a.php')), "\n";
echo count(glob($dir . '/b.php')), "\n";
$entries = scandir($dir);
echo count($entries), "\n";
--EXPECT--
2
1
1
5
