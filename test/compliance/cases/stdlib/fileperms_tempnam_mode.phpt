--TEST--
stdlib fileperms() on tempnam() file — mode 0600 (ext/standard/filestat.c, #14055)
--FILE--
<?php
declare(strict_types=1);
$f = tempnam(sys_get_temp_dir(), 'phpc');
$tail = substr(sprintf('%o', fileperms($f)), -3);
@unlink($f);
echo $tail, "\n";
--EXPECT--
600
