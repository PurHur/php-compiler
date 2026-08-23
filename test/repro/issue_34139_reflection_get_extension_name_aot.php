<?php
class C {}
$lines = [];
foreach ([C::class, DateTime::class, Exception::class, ReflectionClass::class] as $cn) {
    $r = new ReflectionClass($cn);
    $v = $r->getExtensionName();
    $lines[] = $cn.'='.var_export($v, true);
}
echo implode('|', $lines), "\n";
