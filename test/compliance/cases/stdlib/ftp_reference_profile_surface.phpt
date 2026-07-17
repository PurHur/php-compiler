--TEST--
stdlib ftp — procedural + FTP\Connection on reference profile (#20083, ext/ftp/php_ftp.c)
--FILE--
<?php
declare(strict_types=1);

echo 'loaded=', (int) extension_loaded('ftp'), "\n";
echo 'ftp_connect=', (int) function_exists('ftp_connect'), "\n";
echo 'ftp_login=', (int) function_exists('ftp_login'), "\n";
echo 'ftp_pasv=', (int) function_exists('ftp_pasv'), "\n";
echo 'ftp_nlist=', (int) function_exists('ftp_nlist'), "\n";
echo 'ftp_append=', (int) function_exists('ftp_append'), "\n";
echo 'ftp_site=', (int) function_exists('ftp_site'), "\n";
echo 'Connection=', (int) class_exists('FTP\\Connection', false), "\n";
echo 'FTP_BINARY=', (int) (defined('FTP_BINARY') && FTP_BINARY === 2), "\n";
--EXPECT--
loaded=1
ftp_connect=1
ftp_login=1
ftp_pasv=1
ftp_nlist=1
ftp_append=1
ftp_site=1
Connection=1
FTP_BINARY=1
