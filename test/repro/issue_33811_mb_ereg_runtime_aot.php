<?php
declare(strict_types=1);

// #33811 — AOT mb_ereg()/mb_eregi()/mb_ereg_match() with runtime pattern/string (follow-up #33648).
$pat = '^[a-z]+$';
$str = 'hello';
var_export(mb_ereg($pat, $str));
echo "\n";
var_export(mb_eregi('HELLO', 'hello'));
echo "\n";
$pat2 = '^[a-z]+$';
var_export(mb_ereg_match($pat2, 'hello'));
echo "\n";
