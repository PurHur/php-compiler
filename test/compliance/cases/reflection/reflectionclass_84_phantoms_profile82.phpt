--TEST--
ReflectionClass 8.4 APIs phantom on PROFILE=8.2 (#22599, ext/reflection/php_reflection.stub.php)
--ENV--
PHP_COMPILER_PROFILE=8.2
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
try {
    (new ReflectionClass(DateTime::class))->getDeprecatedMessage();
    echo "call=ok\n";
} catch (Error $e) {
    echo 'call=', str_contains($e->getMessage(), 'getDeprecatedMessage') ? 'undefined' : $e->getMessage(), "\n";
}
--EXPECT--
getDeprecatedMessage=0
getDeprecatedVersion=0
getLazyPropertyNames=0
getReadOnlyProperties=0
isStatic=0
call=undefined
