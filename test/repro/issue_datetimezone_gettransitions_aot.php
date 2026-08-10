<?php
// DateTimeZone::getTransitions under thin AOT with variable receiver (re-#26799)
$tz = new DateTimeZone('UTC');
$t = $tz->getTransitions(0, 86400);
echo 'ok:', (is_array($t) && count($t) >= 1) ? '1' : '0', "\n";
