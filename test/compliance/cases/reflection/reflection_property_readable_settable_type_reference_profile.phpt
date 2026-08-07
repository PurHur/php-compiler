--TEST--
ReflectionProperty::getReadableType()/getSettableType() phantom on 8.2 reference profile (#22309, #28532, #7053, ext/reflection/php_reflection.stub.php)
--FILE--
<?php
foreach (['getReadableType', 'getSettableType'] as $method) {
    echo $method, '=', method_exists(ReflectionProperty::class, $method) ? 'yes' : 'no', "\n";
}
--EXPECT--
getReadableType=no
getSettableType=no
