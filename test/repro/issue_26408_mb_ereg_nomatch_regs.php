<?php

declare(strict_types=1);

/**
 * Issue #26408 — mb_ereg()/mb_eregi() no-match must set $regs to [] (php_mbregex.c).
 *
 *   php bin/vm.php test/repro/issue_26408_mb_ereg_nomatch_regs.php
 *   php test/repro/issue_26408_mb_ereg_nomatch_regs.php   # Zend reference
 */

$m = 'keep';
$r = mb_ereg('x(y)', 'zzz', $m);
var_export($r);
echo '/';
var_export($m);
echo "\n";

$m2 = null;
$r2 = mb_ereg('a', 'z', $m2);
var_export($r2);
echo '/';
var_export($m2);
echo "\n";

$m3 = 'keep';
$r3 = mb_eregi('x(y)', 'zzz', $m3);
var_export($r3);
echo '/';
var_export($m3);
echo "\n";

$m4 = null;
$r4 = mb_ereg('(a)', 'xa', $m4);
var_export($r4);
echo '/';
var_export($m4);
echo "\n";
