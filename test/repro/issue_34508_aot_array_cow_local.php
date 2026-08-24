<?php
// AOT local array COW (#34508 / re-#3760) — by-value assign must not alias on write.
$a = [1];
$b = $a;
$b[] = 2;
echo 'append_a=';
var_export($a);
echo "\nappend_b=";
var_export($b);
echo "\n";

$c = [1];
$d = $c;
$d[0] = 9;
echo 'index_c=';
var_export($c);
echo "\nindex_d=";
var_export($d);
echo "\n";

function bump($x)
{
    $x[] = 2;

    return $x;
}
$e = [1];
$f = bump($e);
echo 'param_e=';
var_export($e);
echo "\nparam_f=";
var_export($f);
echo "\n";
