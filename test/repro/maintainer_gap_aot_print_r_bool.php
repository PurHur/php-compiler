<?php
// #24259 — AOT print_r(bool) segfault; var_dump(bool) OK
echo "pr_false=";
print_r(false);
echo "|\n";
echo "pr_true=";
print_r(true);
echo "|\n";
echo "vd=";
var_dump(false);
$a = [false, true];
echo "pr_arr0=";
print_r($a[0]);
echo "|\n";
echo "done\n";
