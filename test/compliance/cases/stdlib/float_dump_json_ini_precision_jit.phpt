--TEST--
json_encode/var_export/print_r honor serialize_precision/precision JIT (#25111)
--FILE--
<?php
declare(strict_types=1);

ini_set('serialize_precision', '10');
ini_set('precision', '10');
echo 'json=', json_encode(1 / 3), "\n";
echo 've=', var_export(1 / 3, true), "\n";
echo 'pr=', print_r(1 / 3, true), "\n";
echo 'ser=', serialize(1 / 3), "\n";
echo 'str=', (string) (1 / 3), "\n";

ini_set('serialize_precision', '10');
ini_set('precision', '6');
echo 'cross_sp10_p6=', json_encode(1 / 3), '|', var_export(1 / 3, true), '|', print_r(1 / 3, true), "\n";

ini_set('serialize_precision', '6');
ini_set('precision', '10');
echo 'cross_sp6_p10=', json_encode(1 / 3), '|', var_export(1 / 3, true), '|', print_r(1 / 3, true), "\n";

ini_restore('serialize_precision');
ini_restore('precision');
echo 'def_json=', json_encode(1 / 3), "\n";
echo 'def_ve=', var_export(1 / 3, true), "\n";
echo 'def_pr=', print_r(1 / 3, true), "\n";
--EXPECT--
json=0.3333333333
ve=0.3333333333
pr=0.3333333333
ser=d:0.3333333333;
str=0.3333333333
cross_sp10_p6=0.3333333333|0.3333333333|0.333333
cross_sp6_p10=0.333333|0.333333|0.3333333333
def_json=0.3333333333333333
def_ve=0.3333333333333333
def_pr=0.33333333333333
