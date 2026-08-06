--TEST--
ReflectionProperty asymmetric set/get probes on 8.4 forward profile (#17939, #28185, ext/reflection/php_reflection.c)
--ENV--
PHP_COMPILER_PROFILE=8.4
--FILE--
<?php
foreach (['isPrivateSet', 'isProtectedSet', 'isPublicSet', 'isPrivateGet', 'isProtectedGet', 'isPublicGet', 'getAsymmetricVisibility'] as $method) {
    echo $method, '=', method_exists(ReflectionProperty::class, $method) ? 'yes' : 'no', "\n";
}
--EXPECT--
isPrivateSet=yes
isProtectedSet=yes
isPublicSet=no
isPrivateGet=yes
isProtectedGet=yes
isPublicGet=yes
getAsymmetricVisibility=yes
