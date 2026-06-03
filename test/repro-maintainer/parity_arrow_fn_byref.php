<?php
$f = fn (&$x) => $x;
$x = 1;
$f($x);
echo $x;
