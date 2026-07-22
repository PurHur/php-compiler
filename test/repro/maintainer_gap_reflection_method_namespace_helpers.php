<?php
namespace DemoNs;
class C { public function m() {} }
$rm = new \ReflectionMethod(C::class, 'm');
var_export(method_exists($rm, 'getShortName'));
echo "\n";
echo $rm->getShortName(), "\n";
var_export($rm->getNamespaceName());
echo "\n";
var_export($rm->inNamespace());
echo "\n";
