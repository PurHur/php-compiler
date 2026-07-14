<?php

error_reporting(E_ALL & ~E_DEPRECATED);

var_export(strftime(null));
echo "\n";
var_export(gmstrftime(null));
echo "\n";

$ts = 946684800;
echo strftime('%Y-%m-%d', $ts), "\n";
echo gmstrftime('%Y-%m-%d', $ts), "\n";
