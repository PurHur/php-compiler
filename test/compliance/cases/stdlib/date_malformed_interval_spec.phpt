--TEST--
stdlib DateInterval malformed spec throws DateMalformedIntervalStringException (#20779, ext/date/php_date.c)
--FILE--
<?php
try {
    new DateInterval('bad');
    echo "no throw\n";
} catch (DateMalformedIntervalStringException $e) {
    echo $e->getMessage(), "\n";
}
?>
--EXPECT--
Unknown or bad format (bad)
