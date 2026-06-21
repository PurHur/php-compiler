<?php
list($a, $b) = 'ab';
var_export([$a, $b]);
echo "\n";
[$c, $d] = 'xy';
var_export([$c, $d]);
echo "\n";
list($e) = $s = 'x';
var_export($e);
echo "\n";
[[ $f ]] = 'x';
var_export($f);
echo "\n";
