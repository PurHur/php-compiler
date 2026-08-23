<?php
class C {}
$lines = [];
foreach ([C::class, DateTime::class, Exception::class, ReflectionClass::class] as $cn) {
    $r = new ReflectionClass($cn);
    $ext = $r->getExtension();
    if (null === $ext) {
        $lines[] = $cn.'=null';
    } else {
        $lines[] = $cn.'='.get_class($ext).':'.$ext->getName();
    }
}
echo implode('|', $lines), "\n";
