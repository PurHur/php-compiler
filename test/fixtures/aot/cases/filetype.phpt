--TEST--
AOT: filetype() via lstat st_mode
--FILE--
<?php
$linkBase = 'test/compliance/cases/stdlib/is_link_fixture';
$link = $linkBase . '/link';
$file = $linkBase . '/target.txt';
$dir = 'test/compliance/cases/stdlib/glob_scandir_fixture';
echo filetype($link), "\n";
echo filetype($file), "\n";
echo filetype($dir), "\n";
--EXPECT--
link
file
dir
