<?php
namespace App;
class C { public function m() {} }
$r = new \ReflectionMethod(C::class, 'm');
foreach (['getNamespaceName', 'getShortName', 'inNamespace', 'isClosure', '__toString'] as $m) {
    echo $m, ':', method_exists($r, $m) ? 'yes' : 'no', "\n";
}
echo 'isClosure=', var_export($r->isClosure(), true), "\n";
echo 'short=', $r->getShortName(), "\n";
$s = (string) $r;
echo (str_contains($s, 'Method [ <user> public method m ]') && str_contains($s, '@@')) ? "tostring-ok\n" : "tostring-bad\n";
