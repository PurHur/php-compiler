<?php

declare(strict_types=1);

foreach (['on=on', 'off=off', 'yes=yes', 'no=no', 'true=true', 'false=false'] as $ini) {
    $prev = error_reporting(E_ALL);
    $result = parse_ini_string($ini);
    error_reporting($prev);
    var_export($result);
    echo "\n";
}
