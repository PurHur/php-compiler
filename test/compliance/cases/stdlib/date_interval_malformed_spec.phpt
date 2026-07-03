--TEST--
stdlib DateInterval malformed spec throws DateMalformedIntervalException (#15382, ext/date/php_date.c)
--FILE--
<?php
try {
    new DateInterval('bad');
    echo "no throw\n";
} catch (DateMalformedIntervalException $e) {
    echo $e->getMessage(), "\n";
}
?>
--EXPECT--
Unknown or bad format (bad)
