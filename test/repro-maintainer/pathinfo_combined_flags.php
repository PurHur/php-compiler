<?php
// Zend: combined PATHINFO_* (not ALL) returns one string by priority (#4049).
$combo = pathinfo('/a/b.c', PATHINFO_DIRNAME | PATHINFO_EXTENSION);
echo $combo, "\n";
$pair = pathinfo('/a/b.c', PATHINFO_BASENAME | PATHINFO_FILENAME);
echo $pair, "\n";
$three = pathinfo('/var/www/index.html', PATHINFO_DIRNAME | PATHINFO_BASENAME | PATHINFO_EXTENSION);
echo $three, "\n";
$all = pathinfo('/var/www/index.html', PATHINFO_ALL);
echo isset($all['dirname']) ? $all['dirname'] : 'no', "\n";
