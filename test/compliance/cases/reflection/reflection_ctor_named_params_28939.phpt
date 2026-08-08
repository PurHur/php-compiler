--TEST--
ReflectionFunction/Class/Method/Property ctor named args + Reflection names (VM, issue #28939)
--FILE--
<?php
echo (new ReflectionFunction(function: 'strlen'))->getName(), PHP_EOL;
echo (new ReflectionClass(objectOrClass: 'stdClass'))->getName(), PHP_EOL;
echo (new ReflectionMethod(objectOrMethod: 'DateTime', method: 'format'))->getName(), PHP_EOL;
echo (new ReflectionProperty(class: 'Exception', property: 'message'))->getName(), PHP_EOL;
foreach (['ReflectionFunction', 'ReflectionClass', 'ReflectionMethod', 'ReflectionProperty'] as $cls) {
    $rf = new ReflectionMethod($cls, '__construct');
    $parts = [];
    foreach ($rf->getParameters() as $p) {
        $parts[] = $p->getName() . ($p->isOptional() ? '=' : '');
    }
    echo $cls, ':', implode(',', $parts), PHP_EOL;
}
--EXPECT--
strlen
stdClass
format
message
ReflectionFunction:function
ReflectionClass:objectOrClass
ReflectionMethod:objectOrMethod,method=
ReflectionProperty:class,property
