--TEST--
stdlib substr(sprintf('%o', fileperms($path)), -N) after stmt-level chmod (#16451, ext/standard/string.c)
--FILE--
<?php
declare(strict_types=1);

$tmp = tempnam(sys_get_temp_dir(), 'phpc');
chmod($tmp, 0644);
echo substr(sprintf('%o', fileperms($tmp)), -4), "\n";
unlink($tmp);
--EXPECT--
0644
