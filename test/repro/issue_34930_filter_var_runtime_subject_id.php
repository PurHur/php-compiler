<?php
$f = FILTER_VALIDATE_EMAIL;
$e = 'a@b.co';
var_export(filter_var($e, $f));
echo "\n";
$f2 = FILTER_VALIDATE_INT;
$v = '42';
var_export(filter_var($v, $f2));
echo "\n";
