<?php
$a = [1, 2];
array_unshift($a, &$a[0]);
$a[0] = 99;
var_export($a);
