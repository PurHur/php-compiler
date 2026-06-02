<?php
$r = sscanf('+42', '%d');
echo isset($r[0]) ? (string)$r[0] : 'null';
echo "\n";
$n = 0;
sscanf('+42', '%d', $n);
echo $n, "\n";
