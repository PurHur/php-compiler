--TEST--
RecursiveTreeIterator rewind on empty directory — no TypeError (#13223, ext/spl/spl_iterators.c)
--FILE--
<?php
$dir = sys_get_temp_dir().'/rti_compliance_'.getmypid();
mkdir($dir);
$rdi = new RecursiveDirectoryIterator($dir, 0);
$rti = new RecursiveTreeIterator($rdi);
$rti->rewind();
echo $rti->valid() ? 'valid' : 'invalid', "\n";
rmdir($dir);
--EXPECT--
valid
