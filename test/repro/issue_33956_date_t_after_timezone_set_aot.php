<?php
// #33956 — AOT date('T') after date_default_timezone_set must follow runtime zone
var_export(date_default_timezone_set('Europe/Berlin'));
echo "\n";
echo date_default_timezone_get(), "\n";
echo date('T', 1721037600), "\n";
echo date('e', 1721037600), "\n";
