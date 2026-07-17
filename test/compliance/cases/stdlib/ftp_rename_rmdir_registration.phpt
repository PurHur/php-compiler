--TEST--
stdlib ftp — ftp_rename/ftp_rmdir registration (#20232, ext/ftp/php_ftp.c)
--FILE--
<?php
declare(strict_types=1);

echo 'ftp_rename=', (int) function_exists('ftp_rename'), "\n";
echo 'ftp_rmdir=', (int) function_exists('ftp_rmdir'), "\n";
echo 'ftp_delete=', (int) function_exists('ftp_delete'), "\n";
--EXPECT--
ftp_rename=1
ftp_rmdir=1
ftp_delete=1
