--TEST--
Reflection: ReflectionFunction::getAttributes() on named functions and closures (#19418, ext/reflection/php_reflection.c)
--FILE--
<?php
#[Attribute]
class Mark {}

#[Mark]
function demo() {}

$rf = new ReflectionFunction('demo');
echo 'named_has=', method_exists($rf, 'getAttributes') ? '1' : '0', "\n";
$attrs = $rf->getAttributes();
echo 'named_count=', count($attrs), ' name=', $attrs[0]->getName(), "\n";

$fn = #[Mark] function () {};
$rc = new ReflectionFunction($fn);
echo 'closure_has=', method_exists($rc, 'getAttributes') ? '1' : '0', "\n";
$closureAttrs = $rc->getAttributes();
echo 'closure_count=', count($closureAttrs), ' name=', $closureAttrs[0]->getName(), "\n";
--EXPECT--
named_has=1
named_count=1 name=Mark
closure_has=1
closure_count=1 name=Mark
