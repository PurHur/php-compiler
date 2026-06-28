--TEST--
FilesystemIterator rewind valid=false before first next (ext/spl/spl_directory.c; #13219)
--FILE--
<?php
declare(strict_types=1);
$dir = sys_get_temp_dir() . '/fsiter_rewind_' . getmypid();
mkdir($dir);
$it = new FilesystemIterator($dir);
$it->rewind();
echo $it->valid() ? "fail\n" : "ok\n";
rmdir($dir);
?>
--EXPECT--
ok
