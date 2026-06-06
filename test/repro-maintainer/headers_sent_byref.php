<?php
echo "x\n";
$f = '';
$l = 0;
var_export(headers_sent($f, $l));
echo "\n{$f}:{$l}\n";
