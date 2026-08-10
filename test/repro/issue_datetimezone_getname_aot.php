<?php
// DateTimeZone::getName under thin AOT with variable receiver (re-#27307)
$z = new DateTimeZone('UTC');
echo $z->getName(), "\n";
