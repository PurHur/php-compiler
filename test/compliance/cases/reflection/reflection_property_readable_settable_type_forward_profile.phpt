--TEST--
ReflectionProperty::getReadableType phantom / getSettableType on 8.4 forward profile (#28532, #22309, #7053, ext/reflection/php_reflection.stub.php)
--ENV--
PHP_COMPILER_PROFILE=8.4
--FILE--
<?php
foreach (['getReadableType', 'getSettableType'] as $method) {
    echo $method, '=', method_exists(ReflectionProperty::class, $method) ? 'yes' : 'no', "\n";
}
--EXPECT--
getReadableType=no
getSettableType=yes
