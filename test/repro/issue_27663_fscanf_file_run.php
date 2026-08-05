<?php
$path = sys_get_temp_dir() . '/fscanf27663b.txt';
file_put_contents($path, "1 2 3");
$f = fopen($path, "r");
$n = fscanf($f, "%d %d %d", $a, $b, $c);
fclose($f);
@unlink($path);
echo "$n:$a:$b:$c\n";
