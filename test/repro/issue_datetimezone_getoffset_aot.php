<?php
// DateTimeZone::getOffset under thin AOT — expect 0 for UTC (re-#27308)
$z = new DateTimeZone('UTC');
$d = new DateTimeImmutable('2020-01-01', $z);
echo $z->getOffset($d), "\n";
