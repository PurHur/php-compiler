<?php
$GLOBALS['k'] = 1;
unset($GLOBALS['k']);
var_export(array_key_exists('k', $GLOBALS));
echo "\n";
