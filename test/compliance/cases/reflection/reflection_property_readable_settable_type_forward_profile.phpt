--TEST--
ReflectionProperty::getReadableType()/getSettableType() on 8.4 forward profile (#22309, #7053, ext/reflection/php_reflection.c)
--ENV--
PHP_COMPILER_PROFILE=8.4
--FILE--
<?php
foreach (['getReadableType', 'getSettableType'] as $method) {
    echo $method, '=', method_exists(ReflectionProperty::class, $method) ? 'yes' : 'no', "\n";
}
--EXPECT--
getReadableType=yes
getSettableType=yes
