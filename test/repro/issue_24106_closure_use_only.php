<?php
$n = 10;
$f = function () use ($n) { return $n; };
echo $f(), "\n";
