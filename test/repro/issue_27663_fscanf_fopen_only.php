<?php
$f = fopen("/tmp/fscanf_fixed.txt", "r");
$n = fscanf($f, "%d %d %d", $a, $b, $c);
fclose($f);
echo "$n:$a:$b:$c\n";
