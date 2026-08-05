<?php
$f = fopen("php://memory", "r+");
fwrite($f, "1 2 3");
rewind($f);
$n = fscanf($f, "%d %d %d", $a, $b, $c);
echo "$n:$a:$b:$c\n";
