--TEST--
stdlib ftp — extension_loaded paired with ftp_* / Ftp\Connection on PROFILE=8.4 (#19672)
--ENV--
PHP_COMPILER_PROFILE=8.4
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
