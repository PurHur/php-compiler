--TEST--
stdlib ftp — advertised on reference profile (#20083, re-#19672, ext/ftp/php_ftp.c)
--FILE--
<?php
declare(strict_types=1);

echo 'loaded=', (int) extension_loaded('ftp'), "\n";
echo 'ftp_connect=', (int) function_exists('ftp_connect'), "\n";
echo 'Connection=', (int) class_exists('Ftp\\Connection', false), "\n";
--EXPECT--
loaded=1
ftp_connect=1
Connection=1
