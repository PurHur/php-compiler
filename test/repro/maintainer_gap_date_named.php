<?php
declare(strict_types=1);

// Issue #9647 — date() format:/timestamp: named parameters (ext/date/php_date.stub.php).

var_export(date(format: 'Y-m-d', timestamp: 0));
echo "\n";
var_export(date(timestamp: 0, format: 'Y'));
echo "\n";
var_export(date('Y-m-d', 0));
echo "\n";
