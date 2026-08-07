--TEST--
ReflectionClass 8.4 APIs on PROFILE=8.4 (#22599, #28516, #28518, ext/reflection/php_reflection.stub.php)
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
// php-src: isStatic is on ReflectionProperty / ReflectionFunctionAbstract only (#28518).
echo 'prop_isStatic=', method_exists(ReflectionProperty::class, 'isStatic') ? '1' : '0', "\n";
--EXPECT--
getDeprecatedMessage=1
getDeprecatedVersion=1
getLazyPropertyNames=0
getReadOnlyProperties=0
isStatic=0
prop_isStatic=1
