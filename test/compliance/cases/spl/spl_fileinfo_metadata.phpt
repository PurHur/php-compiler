--TEST--
SPL SplFileInfo metadata methods (#13190, ext/spl/spl_directory.c)
--RUNFILE--
spl_fileinfo_metadata_run.php
--FILE--
<?php
// body in RUNFILE
--EXPECT--
ext-ok
realpath-ok
isfile-ok
isdir-ok
readable-ok
writable-ok
size-ok
