--TEST--
stdlib ftp — ftp_quit/ftp_exec registration (#20233, ext/ftp/php_ftp.c)
--FILE--
<?php
declare(strict_types=1);

echo 'ftp_quit=', (int) function_exists('ftp_quit'), "\n";
echo 'ftp_exec=', (int) function_exists('ftp_exec'), "\n";
echo 'ftp_close=', (int) function_exists('ftp_close'), "\n";
--EXPECT--
ftp_quit=1
ftp_exec=1
ftp_close=1
