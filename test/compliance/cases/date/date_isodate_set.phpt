--TEST--
date date_isodate_set() ISO week date (ext/date/php_date.c, #20016)
--FILE--
<?php
declare(strict_types=1);
$d = date_create('2000-01-01');
$r = date_isodate_set($d, 2008, 2, 1);
echo $d->format('Y-m-d'), "\n";
echo ($r === $d) ? "same\n" : "diff\n";
$d2 = date_create('2000-01-01');
date_isodate_set($d2, 2008, 2);
echo $d2->format('Y-m-d'), "\n";
try {
    date_isodate_set($d, 2008);
} catch (ArgumentCountError $e) {
    echo $e->getMessage(), "\n";
}
try {
    date_isodate_set(1, 2008, 2);
} catch (TypeError $e) {
    echo $e->getMessage(), "\n";
}
?>
--EXPECT--
2008-01-07
same
2008-01-07
date_isodate_set() expects at least 3 arguments, 2 given
date_isodate_set(): Argument #1 ($object) must be of type DateTime, int given
