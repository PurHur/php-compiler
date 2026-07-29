--TEST--
ext calendar unixtojd Reflection ?int=null (VM, issue #24863)
--FILE--
<?php
$r = new ReflectionFunction('unixtojd');
$p = $r->getParameters()[0];
echo $p->getName(), ' type=', $p->getType(), ' allowsNull=', (int) $p->allowsNull(), PHP_EOL;
echo 'default=', var_export($p->getDefaultValue(), true), PHP_EOL;
$a = unixtojd(null);
$b = unixtojd();
echo 'null_ok=', (int) is_int($a), ' same_as_omitted=', (int) ($a === $b), PHP_EOL;
?>
--EXPECT--
timestamp type=?int allowsNull=1
default=NULL
null_ok=1 same_as_omitted=1
