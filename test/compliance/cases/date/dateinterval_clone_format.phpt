--TEST--
date DateInterval clone format and from-string wire (ext/date/php_date.c, #22893)
--FILE--
<?php
declare(strict_types=1);
$i = new DateInterval('P1Y2M3DT4H5M6S');
$c = clone $i;
echo $c->format('%Y-%M-%D'), "\n";
echo 'y=', $c->y, ' m=', $c->m, ' d=', $c->d, "\n";
$f = DateInterval::createFromDateString('1 day');
$cf = clone $f;
echo 'from=', $cf->format('%d'), "\n";
echo 'gov=', json_encode(get_object_vars($cf)), "\n";
--EXPECT--
01-02-03
y=1 m=2 d=3
from=1
gov={"from_string":true,"date_string":"1 day"}
