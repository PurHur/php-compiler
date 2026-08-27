<?php
declare(strict_types=1);

// AOT: mb_ereg()/mb_eregi() &$regs must match Zend (leftover of #33811).
mb_ereg('a+', 'aaa', $m);
echo ($m[0] ?? '(none)'), "\n";

$r = mb_ereg('a+', 'aaa', $m2);
var_export($r);
echo "\n", ($m2[0] ?? '(none)'), "\n";

$keep = ['x'];
$r2 = mb_ereg('z+', 'aaa', $keep);
var_export($r2);
echo "\n";
var_export($keep);
echo "\n";

$r3 = mb_eregi('A+', 'aaa', $mi);
var_export($r3);
echo "\n", ($mi[0] ?? '(none)'), "\n";
