--TEST--
DateTimeZone::getTransitions() time field is UTC ISO-8601 (#30971, php-src zim_DateTimeZone_getTransitions)
--FILE--
<?php
declare(strict_types=1);
$ts = 1700000000;
$expect = '2023-11-14T22:13:20+00:00';
foreach (['UTC', 'Europe/Berlin', 'Asia/Tokyo', 'America/New_York'] as $name) {
    $oop = (new DateTimeZone($name))->getTransitions($ts, $ts)[0];
    $proc = timezone_transitions_get(new DateTimeZone($name), $ts, $ts)[0];
    echo $name, ' ', $oop['time'], ' offset=', $oop['offset'], "\n";
    echo $name, '_proc ', $proc['time'], "\n";
    if ($oop['time'] !== $expect || $proc['time'] !== $expect) {
        echo "FAIL time\n";
    }
}
?>
--EXPECT--
UTC 2023-11-14T22:13:20+00:00 offset=0
UTC_proc 2023-11-14T22:13:20+00:00
Europe/Berlin 2023-11-14T22:13:20+00:00 offset=3600
Europe/Berlin_proc 2023-11-14T22:13:20+00:00
Asia/Tokyo 2023-11-14T22:13:20+00:00 offset=32400
Asia/Tokyo_proc 2023-11-14T22:13:20+00:00
America/New_York 2023-11-14T22:13:20+00:00 offset=-18000
America/New_York_proc 2023-11-14T22:13:20+00:00
