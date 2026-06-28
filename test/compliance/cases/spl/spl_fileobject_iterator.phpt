--TEST--
SPL SplFileObject Iterator API — rewind/key/valid/eof/current/next (#13119, #13126)
--RUNFILE--
spl_fileobject_iterator_run.php
--FILE--
<?php
// body in RUNFILE
--EXPECT--
rewind-fgets-ok
foreach-ok
