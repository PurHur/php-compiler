--TEST--
stdlib SplFileObject::getCurrentLine() after fseek — next line not current (ext/spl/spl_directory.c, #14252)
--FILE--
<?php
file_put_contents('/tmp/splfile_gcl_compliance.txt', "line0\nline1\nline2\n");
$fo = new SplFileObject('/tmp/splfile_gcl_compliance.txt');
$fo->fseek(0);
echo $fo->current();
echo $fo->getCurrentLine();
?>
--EXPECT--
line0
line1
