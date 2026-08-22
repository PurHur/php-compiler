--TEST--
get_defined_functions Reflection $exclude_disabled is bool (#26356)
--FILE--
<?php
$r = new ReflectionFunction('get_defined_functions');
$p = $r->getParameters()[0];
echo 'param=$', $p->getName(), ':', $p->hasType() ? (string) $p->getType() : 'none';
echo ' opt=', $p->isOptional() ? 'yes' : 'no';
echo ' def=', var_export($p->isDefaultValueAvailable() ? $p->getDefaultValue() : null, true), "\n";
echo 'return=', $r->hasReturnType() ? (string) $r->getReturnType() : '<none>', "\n";
?>
--EXPECT--
param=$exclude_disabled:bool opt=yes def=true
return=array
