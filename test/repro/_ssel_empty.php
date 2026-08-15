<?php
$r = [];
$w = null;
$e = null;
$n = socket_select($r, $w, $e, 0);
echo 'n=', var_export($n, true), "\n";
