--TEST--
stdlib mb_ereg()/mb_eregi() no-match assigns $regs=[] (ext/mbstring/php_mbregex.c, #26408)
--FILE--
<?php
declare(strict_types=1);

$m = 'keep';
$r = mb_ereg('x(y)', 'zzz', $m);
var_export($r);
echo '/';
var_export($m);
echo "\n";

$m = null;
$r = mb_ereg('a', 'z', $m);
var_export($r);
echo '/';
var_export($m);
echo "\n";

$m = 'keep';
$r = mb_eregi('x(y)', 'zzz', $m);
var_export($r);
echo '/';
var_export($m);
echo "\n";

$m = null;
$r = mb_ereg('(a)', 'xa', $m);
var_export($r);
echo '/';
var_export($m);
echo "\n";

// Two-arg form must not invent a regs slot.
$r = mb_ereg('x', 'zzz');
var_export($r);
echo "\n";
?>
--EXPECT--
false/array (
)
false/array (
)
false/array (
)
true/array (
  0 => 'a',
  1 => 'a',
)
false
