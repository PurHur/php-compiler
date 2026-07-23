<?php
$a = $b = null;
var_export(sscanf('1 2 3', '%d%*d%d', $a, $b));
echo " a=", var_export($a, true), " b=", var_export($b, true), "\n";
