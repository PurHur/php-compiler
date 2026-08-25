<?php
// #34584 — AOT serialize(DateInterval/DateTimeZone) Zend wire (peer #34576 / re-#10692).
declare(strict_types=1);

echo serialize(new DateInterval('P1D')), "\n";
echo serialize(new DateTimeZone('UTC')), "\n";
echo serialize(new DateInterval('PT2H30M')), "\n";
echo serialize(new DateTimeZone('Europe/Berlin')), "\n";
