<?php
/**
 * #24863 — unixtojd Reflection ?int=null + null→today JD.
 */
$r = new ReflectionFunction('unixtojd');
$p = $r->getParameters()[0];
echo $p->getName(), ' type=', $p->getType(), ' allowsNull=', (int) $p->allowsNull(), ' default=';
var_export($p->isDefaultValueAvailable() ? $p->getDefaultValue() : 'n/a');
echo "\n";
$a = unixtojd(null);
$b = unixtojd();
echo 'null=', $a, ' omitted=', $b, ' match=', (int) ($a === $b), "\n";
