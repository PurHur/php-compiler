--TEST--
SPL SplFileInfo getFileInfo/getPathInfo/openFile + setFileClass/setInfoClass (#20090, ext/spl/spl_directory.c)
--RUNFILE--
splfileinfo_info_class_factory_run.php
--FILE--
<?php
// body in RUNFILE
--EXPECT--
11111
SplFileInfo path-ok
SplFileInfo dir-ok
MyInfo20090
MyFile20090 hello
SplFileInfo SplFileObject
bad-info-typeerror
