--TEST--
stdlib DateTimeZone::getLocation / timezone_location_get lat/long from zone.tab (#22291, ext/date/php_date.c)
--FILE--
<?php
function approx(float $got, float $want, float $eps = 0.00002): string
{
    return abs($got - $want) <= $eps ? 'ok' : "fail got={$got} want={$want}";
}

$cases = [
    'America/New_York' => ['US', 40.71416, -74.00638, 'Eastern (most areas)'],
    'Europe/London' => ['GB', 51.50833, -0.12527, ''],
    'Asia/Tokyo' => ['JP', 35.65444, 139.74472, ''],
    'UTC' => ['??', 0.0, 0.0, '?'],
    'Europe/Berlin' => ['DE', 52.5, 13.36666, null], // comments vary by zone.tab; skip strict match
];

foreach ($cases as $id => [$country, $lat, $lon, $comments]) {
    $tz = new DateTimeZone($id);
    $oop = $tz->getLocation();
    $proc = timezone_location_get($tz);
    echo $id, "\n";
    echo '  country=', ($oop['country_code'] === $country && $proc['country_code'] === $country) ? 'ok' : 'fail', "\n";
    echo '  oop_lat=', approx((float) $oop['latitude'], $lat), "\n";
    echo '  oop_lon=', approx((float) $oop['longitude'], $lon), "\n";
    echo '  proc_lat=', approx((float) $proc['latitude'], $lat), "\n";
    echo '  proc_lon=', approx((float) $proc['longitude'], $lon), "\n";
    if (null !== $comments) {
        echo '  comments=', ($oop['comments'] === $comments) ? 'ok' : 'fail', "\n";
    }
}
--EXPECT--
America/New_York
  country=ok
  oop_lat=ok
  oop_lon=ok
  proc_lat=ok
  proc_lon=ok
  comments=ok
Europe/London
  country=ok
  oop_lat=ok
  oop_lon=ok
  proc_lat=ok
  proc_lon=ok
  comments=ok
Asia/Tokyo
  country=ok
  oop_lat=ok
  oop_lon=ok
  proc_lat=ok
  proc_lon=ok
  comments=ok
UTC
  country=ok
  oop_lat=ok
  oop_lon=ok
  proc_lat=ok
  proc_lon=ok
  comments=ok
Europe/Berlin
  country=ok
  oop_lat=ok
  oop_lon=ok
  proc_lat=ok
  proc_lon=ok
