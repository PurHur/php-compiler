--TEST--
SPL SplFileInfo/SplFileObject __debugInfo private bags (#20108, ext/spl/spl_directory.c)
--RUNFILE--
splfileinfo_debuginfo_run.php
--FILE--
<?php
// body in RUNFILE
--EXPECT--
1
path-ok
file-ok
1
mode-ok
delim-ok
encl-ok
