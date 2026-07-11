--TEST--
ReflectionProperty asymmetric set/get probes phantom on 8.2 reference profile (#17939, ext/reflection/php_reflection.c)
--FILE--
<?php
foreach (['isPrivateSet', 'isProtectedSet', 'isPublicSet', 'isPrivateGet', 'isProtectedGet', 'isPublicGet', 'getAsymmetricVisibility'] as $method) {
    echo $method, '=', method_exists(ReflectionProperty::class, $method) ? 'yes' : 'no', "\n";
}
--EXPECT--
isPrivateSet=no
isProtectedSet=no
isPublicSet=no
isPrivateGet=no
isProtectedGet=no
isPublicGet=no
getAsymmetricVisibility=no
