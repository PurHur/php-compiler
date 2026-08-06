--TEST--
stdlib libxml_set_external_entity_loader Reflection stubs (#27744)
--FILE--
<?php
$r = new ReflectionFunction('libxml_set_external_entity_loader');
$p = $r->getParameters()[0];
echo 'param=', $p->hasType() ? (string) $p->getType() : '(none)', "\n";
echo 'return=', $r->hasReturnType() ? (string) $r->getReturnType() : '(none)', "\n";
echo 'null_ok=', (int) libxml_set_external_entity_loader(null), "\n";
?>
--EXPECT--
param=?callable
return=bool
null_ok=1
