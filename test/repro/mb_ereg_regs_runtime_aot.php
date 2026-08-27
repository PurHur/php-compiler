<?php
declare(strict_types=1);

// #35297 — AOT mb_ereg()/mb_eregi() &$regs with runtime pattern (NestedJIT path).
$pat = 'a+';
$str = 'aaa';
mb_ereg($pat, $str, $m);
echo ($m[0] ?? '(none)'), "\n";
var_export(mb_ereg($pat, $str, $m2));
echo "\n", ($m2[0] ?? '(none)'), "\n";
$patI = 'A+';
var_export(mb_eregi($patI, 'aaa', $mi));
echo "\n", ($mi[0] ?? '(none)'), "\n";
