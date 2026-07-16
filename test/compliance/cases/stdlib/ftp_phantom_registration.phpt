--TEST--
stdlib ftp — not advertised on reference profile (#19672, ext/ftp/php_ftp.c)
--FILE--
<?php
declare(strict_types=1);

echo 'loaded=', (int) extension_loaded('ftp'), "\n";
echo 'ftp_connect=', (int) function_exists('ftp_connect'), "\n";
echo 'Connection=', (int) class_exists('Ftp\\Connection', false), "\n";
--EXPECT--
loaded=0
ftp_connect=0
Connection=0
