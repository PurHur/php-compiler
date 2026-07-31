<?php
/**
 * MessageFormatter {0,date}/{0,time} must format via ICU styles, not stringify (#25226).
 * php-src: ext/intl/msgformat/msgformat_helpers.cpp
 */
date_default_timezone_set('UTC');
$ts = strtotime('2024-07-15 UTC');
$tsTime = strtotime('2024-07-15 15:30:00 UTC');

$cases = [
    ['{0,date}', $ts],
    ['{0,date,short}', $ts],
    ['{0,date,medium}', $ts],
    ['{0,date,long}', $ts],
    ['{0,date,full}', $ts],
    ['{0,time}', $tsTime],
    ['{0,time,short}', $tsTime],
    ['{0,time,medium}', $tsTime],
    ['{0,time,long}', $tsTime],
    ['{0,time,full}', $tsTime],
];

foreach ($cases as [$pattern, $arg]) {
    $fmt = new MessageFormatter('en_US', $pattern);
    echo $pattern, ' => ', $fmt->format([$arg]), "\n";
}

date_default_timezone_set('America/New_York');
$tsNy = strtotime('2024-07-15 15:30:00 UTC');
echo '{0,time,long}@NY => ', (new MessageFormatter('en_US', '{0,time,long}'))->format([$tsNy]), "\n";
