<?php

declare(strict_types=1);

$di = date_interval_create_from_date_string('1 day + 2 hours');
var_export($di !== false);
echo "\n";
if ($di !== false) {
    var_export($di->format('%d days %h hours'));
    echo "\n";
}
