<?php
declare(strict_types=1);
$rf = new ReflectionFunction("strlen");
echo "hasReturnType=", $rf->hasReturnType() ? "1" : "0", "\n";
$rt = $rf->getReturnType();
echo "getReturnType=", $rt ? $rt->getName() : "NULL", "\n";
echo "hasTentativeReturnType_method=", method_exists($rf, "hasTentativeReturnType") ? "1" : "0", "\n";
if (method_exists($rf, "hasTentativeReturnType")) {
    echo "hasTentative=", $rf->hasTentativeReturnType() ? "1" : "0", "\n";
}
foreach (['count', 'array_keys', 'is_string'] as $name) {
    $r = new ReflectionFunction($name);
    $t = $r->getReturnType();
    echo $name, "=", $r->hasReturnType() ? ($t instanceof ReflectionNamedType ? $t->getName() : '?') : 'none', "\n";
}
