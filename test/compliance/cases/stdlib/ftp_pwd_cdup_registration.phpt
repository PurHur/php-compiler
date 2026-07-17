--TEST--
stdlib ftp — ftp_pwd/ftp_cdup registration (#20231, ext/ftp/php_ftp.c)
--FILE--
<?php
declare(strict_types=1);

echo 'ftp_pwd=', (int) function_exists('ftp_pwd'), "\n";
echo 'ftp_cdup=', (int) function_exists('ftp_cdup'), "\n";
echo 'ftp_chdir=', (int) function_exists('ftp_chdir'), "\n";
--EXPECT--
ftp_pwd=1
ftp_cdup=1
ftp_chdir=1
