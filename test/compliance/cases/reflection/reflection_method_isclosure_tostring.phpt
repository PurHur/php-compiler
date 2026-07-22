--TEST--
ReflectionMethod isClosure/__toString + namespace helpers (#22173)
--FILE--
<?php
namespace App;
class C { public function m() {} }
$r = new \ReflectionMethod(C::class, 'm');
foreach (['getNamespaceName', 'getShortName', 'inNamespace', 'isClosure', '__toString'] as $m) {
    echo $m, ':', method_exists($r, $m) ? 'yes' : 'no', "\n";
}
echo 'isClosure=', var_export($r->isClosure(), true), "\n";
echo 'short=', $r->getShortName(), "\n";
echo 'ns=', var_export($r->getNamespaceName(), true), "\n";
echo 'in=', var_export($r->inNamespace(), true), "\n";
$s = (string) $r;
echo (str_contains($s, 'Method [ <user> public method m ]') && str_contains($s, '@@')) ? "tostring-ok\n" : "tostring-bad\n";
?>
--EXPECT--
getNamespaceName:yes
getShortName:yes
inNamespace:yes
isClosure:yes
__toString:yes
isClosure=false
short=m
ns=''
in=false
tostring-ok
