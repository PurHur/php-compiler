--TEST--
stdlib DateTime::__construct malformed string — catchable Exception not warning-only (#7113, ext/date/php_date.c)
--FILE--
<?php
try {
    new DateTime('not-a-date');
    echo "no throw\n";
} catch (Exception $e) {
    echo 'caught ', get_class($e), "\n";
    echo $e->getMessage(), "\n";
}
--EXPECT--
caught Exception
Failed to parse time string (not-a-date) at position 0 (n): The timezone could not be found in the database
