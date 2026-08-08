--TEST--
ext calendar unixtojd out-of-range → false + Reflection int|false (VM, issue #28780)
--FILE--
<?php
$r = new ReflectionFunction('unixtojd');
echo 'ret=', $r->hasReturnType() ? (string) $r->getReturnType() : 'none', PHP_EOL;
echo 'max=', var_export(unixtojd(PHP_INT_MAX), true), PHP_EOL;
echo 'epoch_ok=', (int) is_int(unixtojd(0)), PHP_EOL;
?>
--EXPECT--
ret=int|false
max=false
epoch_ok=1
