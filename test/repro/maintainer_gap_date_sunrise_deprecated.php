<?php
declare(strict_types=1);

error_reporting(E_ALL);
ini_set('display_errors', '0');

@date_sunrise(time());
$rise = error_get_last();
echo 'sunrise_dep='.($rise['message'] ?? '')."\n";

@date_sunset(time());
$set = error_get_last();
echo 'sunset_dep='.($set['message'] ?? '')."\n";
