--TEST--
JIT: DateTimeZone::getLocation / timezone_location_get lat/lon bit-match Zend (#30953, ext/date/php_date.c)
--FILE--
<?php
$cases = [
    'America/New_York' => 'US',
    'Europe/London' => 'GB',
    'Asia/Tokyo' => 'JP',
    'Australia/Sydney' => 'AU',
    'Europe/Berlin' => 'DE',
    'UTC' => '??',
];

foreach ($cases as $id => $country) {
    $tz = new DateTimeZone($id);
    $oop = $tz->getLocation();
    $proc = timezone_location_get($tz);
    $geo = static function (array $loc): string {
        return json_encode([
            'country_code' => $loc['country_code'],
            'latitude' => $loc['latitude'],
            'longitude' => $loc['longitude'],
        ]);
    };
    echo $id, "\n";
    echo '  country=', ($oop['country_code'] === $country && $proc['country_code'] === $country) ? 'ok' : 'fail', "\n";
    echo '  oop=', $geo($oop), "\n";
    echo '  proc=', $geo($proc), "\n";
    echo '  oop_eq_proc=', $geo($oop) === $geo($proc) ? 'ok' : 'fail', "\n";
}
echo 'berlin_json=', json_encode((new DateTimeZone('Europe/Berlin'))->getLocation()), "\n";
--EXPECT--
America/New_York
  country=ok
  oop={"country_code":"US","latitude":40.71416,"longitude":-74.00638}
  proc={"country_code":"US","latitude":40.71416,"longitude":-74.00638}
  oop_eq_proc=ok
Europe/London
  country=ok
  oop={"country_code":"GB","latitude":51.50833,"longitude":-0.12527}
  proc={"country_code":"GB","latitude":51.50833,"longitude":-0.12527}
  oop_eq_proc=ok
Asia/Tokyo
  country=ok
  oop={"country_code":"JP","latitude":35.65444,"longitude":139.74472}
  proc={"country_code":"JP","latitude":35.65444,"longitude":139.74472}
  oop_eq_proc=ok
Australia/Sydney
  country=ok
  oop={"country_code":"AU","latitude":-33.86666,"longitude":151.21666}
  proc={"country_code":"AU","latitude":-33.86666,"longitude":151.21666}
  oop_eq_proc=ok
Europe/Berlin
  country=ok
  oop={"country_code":"DE","latitude":52.5,"longitude":13.36666}
  proc={"country_code":"DE","latitude":52.5,"longitude":13.36666}
  oop_eq_proc=ok
UTC
  country=ok
  oop={"country_code":"??","latitude":0,"longitude":0}
  proc={"country_code":"??","latitude":0,"longitude":0}
  oop_eq_proc=ok
berlin_json={"country_code":"DE","latitude":52.5,"longitude":13.36666,"comments":"most of Germany"}
