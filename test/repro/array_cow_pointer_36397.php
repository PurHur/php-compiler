<?php
// Repro for #36397 slice 13: AOT next/end must SEPARATE before mutating internalPointer.
$a = [10, 20, 30];
$b = $a;
$n = next($a);
echo 'next=', var_export($n, true), "\n";
echo 'cur_a=', var_export(current($a), true), ' cur_b=', var_export(current($b), true), "\n";
$e = end($a);
echo 'end=', var_export($e, true), "\n";
echo 'cur_a2=', var_export(current($a), true), ' cur_b2=', var_export(current($b), true), "\n";
$key_a = key($a);
$key_b = key($b);
echo 'key_a=', var_export($key_a, true), ' key_b=', var_export($key_b, true), "\n";
