<?php
$c = IntlCalendar::createInstance("Europe/Paris", "fr_FR");
$ms = (float)(strtotime("2024-07-15 12:00:00 UTC")*1000);
$c->setTime($ms);
echo "utc_ms set dst=", (int)$c->inDaylightTime(), " time=", $c->getTime(), "\n";
$ms2 = (float)(strtotime("2024-01-15 12:00:00 UTC")*1000);
$c->setTime($ms2);
echo "jan dst=", (int)$c->inDaylightTime(), "\n";
