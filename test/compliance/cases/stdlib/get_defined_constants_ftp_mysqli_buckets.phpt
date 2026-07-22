--TEST--
get_defined_constants(true) FTP/mysqli/gmp buckets — no spurious user (#22337, re-#19113, basic_functions.c)
--FILE--
<?php
declare(strict_types=1);

$c = get_defined_constants(true);
$userCount = isset($c['user']) ? count($c['user']) : 0;
echo $userCount === 0 ? "user_ok\n" : "user_bad keys={$userCount}\n";

if (extension_loaded('ftp')) {
    echo isset($c['ftp']['FTP_ASCII']) ? "ftp_ok\n" : "ftp_bad\n";
    echo isset($c['user']['FTP_ASCII']) ? "ftp_in_user\n" : "ftp_not_user\n";
} else {
    echo !isset($c['ftp']) ? "ftp_ok\n" : "ftp_bad\n";
    echo "ftp_not_user\n";
}

if (extension_loaded('mysqli')) {
    echo isset($c['user']['MYSQLI_ASSOC']) ? "mysqli_in_user\n" : "mysqli_not_user\n";
} else {
    echo isset($c['user']['MYSQLI_ASSOC']) ? "mysqli_in_user\n" : "mysqli_not_user\n";
}

define('USER_CONST_22337', 1);
$c2 = get_defined_constants(true);
echo isset($c2['user']['USER_CONST_22337']) ? "define_user_ok\n" : "define_user_bad\n";
--EXPECT--
user_ok
ftp_ok
ftp_not_user
mysqli_not_user
define_user_ok
