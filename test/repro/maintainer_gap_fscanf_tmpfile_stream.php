<?php

$f = tmpfile();
$n = fwrite($f, '42 answer');
rewind($f);
$r = fscanf($f, '%d %s');
var_dump($n, $r);
