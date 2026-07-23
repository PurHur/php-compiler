--TEST--
date (array) cast DateTime/DateTimeImmutable/DateTimeZone Zend wire (#22424, ext/date/php_date.c)
--FILE--
<?php
declare(strict_types=1);
$d = new DateTime('2020-01-01 12:30:45', new DateTimeZone('America/New_York'));
var_export((array) $d);
echo "\n";
$z = new DateTimeZone('UTC');
var_export((array) $z);
echo "\n";
$i = new DateTimeImmutable('2020-01-01', new DateTimeZone('UTC'));
var_export((array) $i);
echo "\n";
$leak = false;
foreach (array_keys((array) $d) as $k) {
    if (str_starts_with((string) $k, '__dt_')) {
        $leak = true;
    }
}
echo $leak ? "leak\n" : "noleak\n";
?>
--EXPECT--
array (
  'date' => '2020-01-01 12:30:45.000000',
  'timezone_type' => 3,
  'timezone' => 'America/New_York',
)
array (
  'timezone_type' => 3,
  'timezone' => 'UTC',
)
array (
  'date' => '2020-01-01 00:00:00.000000',
  'timezone_type' => 3,
  'timezone' => 'UTC',
)
noleak
