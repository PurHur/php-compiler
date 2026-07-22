--TEST--
ReflectionMethod getShortName/getNamespaceName/inNamespace (#22167)
--FILE--
<?php
namespace DemoNs;
class C { public function m() {} }
$rm = new \ReflectionMethod(C::class, 'm');
echo method_exists($rm, 'getShortName') ? 'yes' : 'no', "\n";
echo $rm->getShortName(), "\n";
var_export($rm->getNamespaceName());
echo "\n";
var_export($rm->inNamespace());
echo "\n";
// Non-namespaced class method — same Zend surface.
class GlobalC { public function g() {} }
$rg = new \ReflectionMethod(GlobalC::class, 'g');
echo $rg->getShortName(), '|', var_export($rg->getNamespaceName(), true), '|', var_export($rg->inNamespace(), true), "\n";
?>
--EXPECT--
yes
m
''
false
g|''|false
