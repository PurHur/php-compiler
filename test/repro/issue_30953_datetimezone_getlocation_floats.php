<?php
// DateTimeZone::getLocation / timezone_location_get lat/lon vs Zend (#30953)
// AOT construct requires a compile-time string $timezone (#26772).
$berlin = new DateTimeZone('Europe/Berlin');
$oop = $berlin->getLocation();
$proc = timezone_location_get($berlin);
printf("Berlin oop lon=%.17g lat=%.17g\n", $oop['longitude'], $oop['latitude']);
printf("Berlin proc lon=%.17g lat=%.17g\n", $proc['longitude'], $proc['latitude']);
$ny = new DateTimeZone('America/New_York');
$nyLoc = $ny->getLocation();
printf("NY oop lon=%.17g lat=%.17g\n", $nyLoc['longitude'], $nyLoc['latitude']);
$tokyo = new DateTimeZone('Asia/Tokyo');
$tokyoLoc = $tokyo->getLocation();
printf("Tokyo oop lon=%.17g lat=%.17g\n", $tokyoLoc['longitude'], $tokyoLoc['latitude']);
$sydney = new DateTimeZone('Australia/Sydney');
$sydneyLoc = $sydney->getLocation();
printf("Sydney oop lon=%.17g lat=%.17g\n", $sydneyLoc['longitude'], $sydneyLoc['latitude']);
echo json_encode($oop), "\n";
