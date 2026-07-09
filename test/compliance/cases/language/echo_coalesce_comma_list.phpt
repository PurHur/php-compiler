--TEST--
echo comma-list operand with ?? on array dim (#17476)
--FILE--
<?php
declare(strict_types=1);

$a = ['k' => 'v', 'n' => 0];
echo ($a['k'] ?? 'x'), "\n";
echo 'zero=', ($a['n'] ?? 'x'), "\n";
echo 'missing=', ($a['missing'] ?? 'fallback'), "\n";
?>
--EXPECT--
v
zero=0
missing=fallback
