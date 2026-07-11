--TEST--
SPL SplFileInfo::getPath()/getFilename()/getBasename()/getPathname() (#12521, ext/spl/spl_directory.c)
--RUNFILE--
spl_fileinfo_getpath_run.php
--FILE--
<?php
// body in RUNFILE
--EXPECT--
/tmp/example/sub
file.php
file
/tmp/example/sub/file.php
/tmp/example/sub/file.php
