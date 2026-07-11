--TEST--
scandir() SCANDIR_SORT_DESCENDING reverses dot entries (#10546, ext/standard/dir.c)
--FILE--
<?php
$dir = sys_get_temp_dir();
$asc = scandir($dir, SCANDIR_SORT_ASCENDING);
$desc = scandir($dir, SCANDIR_SORT_DESCENDING);
echo $asc[0], ',', $asc[1], "\n";
echo $desc[0], ',', $desc[1], "\n";
--EXPECT--
.,..
..,.
