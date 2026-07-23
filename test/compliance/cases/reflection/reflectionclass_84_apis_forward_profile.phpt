--TEST--
ReflectionClass 8.4 APIs present on PROFILE=8.4 (#22599, ext/reflection/php_reflection.stub.php)
--ENV--
PHP_COMPILER_PROFILE=8.4
--FILE--
<?php
foreach ([
    'getDeprecatedMessage',
    'getDeprecatedVersion',
    'getLazyPropertyNames',
    'getReadOnlyProperties',
    'isStatic',
] as $method) {
    echo $method, '=', method_exists(ReflectionClass::class, $method) ? '1' : '0', "\n";
}
--EXPECT--
getDeprecatedMessage=1
getDeprecatedVersion=1
getLazyPropertyNames=1
getReadOnlyProperties=1
isStatic=1
