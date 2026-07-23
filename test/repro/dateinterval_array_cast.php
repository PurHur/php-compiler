<?php
declare(strict_types=1);

$d = new DateInterval('P1Y2M3DT4H5M6S');
var_export((array) $d);
echo "\n";

$f = DateInterval::createFromDateString('1 day');
var_export((array) $f);
echo "\n";
