<?php
$f = function ($x) { static $n = 0; return $x . (++$n); };
$g = clone $f;
echo $f("a"), "\n";
echo $g("b"), "\n";
echo $f("c"), "\n";
