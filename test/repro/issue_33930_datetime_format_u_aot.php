<?php

declare(strict_types=1);

// AOT: date/DateTime::format('u') + format('H:i:s.u') (#33930 / re-#33927).
echo 'date_u=', date('u', 0), "\n";

$d = new DateTime('2020-01-01');
echo 'bare_u=', $d->format('u'), "\n";

$d2 = new DateTime('2020-01-01');
$d2->setTime(12, 30, 45, 123456);
echo 'set_hisu=', $d2->format('H:i:s.u'), "\n";

$d3 = new DateTime('2020-01-01 12:30:45.123456');
echo 'ctor_hisu=', $d3->format('H:i:s.u'), "\n";

echo 'ymd_hisu=', date('Y-m-d H:i:s.u', 0), "\n";
