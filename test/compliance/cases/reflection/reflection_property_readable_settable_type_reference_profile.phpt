--TEST--
ReflectionProperty::getReadableType()/getSettableType() phantom on 8.2 reference profile (#22309, #7053, ext/reflection/php_reflection.c)
--FILE--
<?php
foreach (['getReadableType', 'getSettableType'] as $method) {
    echo $method, '=', method_exists(ReflectionProperty::class, $method) ? 'yes' : 'no', "\n";
}
--EXPECT--
getReadableType=no
getSettableType=no
