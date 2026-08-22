<?php
// AOT: date_default_timezone_get/set NestedJIT coerce (#33950)
echo date_default_timezone_get(), "\n";
var_export(date_default_timezone_set('Europe/Berlin'));
echo "\n";
echo date_default_timezone_get(), "\n";
var_export(date_default_timezone_set('Not/AZone'));
echo "\n";
echo date_default_timezone_get(), "\n";
