<?php
echo "body\n";
$f = '';
$l = 0;
var_export(headers_sent($f, $l));
echo "\n{$l}\n";
echo basename($f), "\n";
