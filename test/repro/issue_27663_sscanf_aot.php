<?php
$a = $b = $c = 0;
$n = sscanf("1 2 3", "%d %d %d", $a, $b, $c);
echo "$n:$a:$b:$c\n";
