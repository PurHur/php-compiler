--TEST--
stdlib ftp — ftp_nb_fput registration (#20234, ext/ftp/php_ftp.c)
--FILE--
<?php
declare(strict_types=1);

echo 'ftp_nb_fput=', (int) function_exists('ftp_nb_fput'), "\n";
echo 'ftp_nb_fget=', (int) function_exists('ftp_nb_fget'), "\n";
echo 'ftp_fput=', (int) function_exists('ftp_fput'), "\n";
--EXPECT--
ftp_nb_fput=1
ftp_nb_fget=1
ftp_fput=1
