<?php
// DateTimeZone::getOffset under thin AOT — expect 0 for UTC (#34853 / re-#27308 / #29732)
$z = new DateTimeZone('UTC');
$d = new DateTimeImmutable('2020-01-01', $z);
echo $z->getOffset($d), "\n";
