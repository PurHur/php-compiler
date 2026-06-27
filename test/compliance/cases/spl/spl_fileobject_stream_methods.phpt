--TEST--
SPL SplFileObject::fgets()/fwrite() stream I/O (#12520, ext/spl/spl_directory.c)
--RUNFILE--
spl_fileobject_stream_methods_run.php
--FILE--
<?php
// body in RUNFILE
--EXPECT--
fgets-ok
fwrite-ok
