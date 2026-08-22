<?php
// #33930 — date/DateTime::format('u') and format('H:i:s.u') must match Zend under AOT.
echo 'date_u=', date('u', 0), "\n";

$d = new DateTime('2020-01-01');
echo 'fmt_u=', $d->format('u'), "\n";

$d->setTime(12, 30, 45, 123456);
echo 'set_hisu=', $d->format('H:i:s.u'), "\n";

$f = new DateTime('2020-01-01 12:30:45.123456');
echo 'ctor_hisu=', $f->format('H:i:s.u'), "\n";
