--TEST--
ReflectionProperty getRawValue/setRawValue on PROFILE=8.4; phantoms stay absent (#22601)
--ENV--
PHP_COMPILER_PROFILE=8.4
--FILE--
<?php
foreach ([
    'getRawValue',
    'setRawValue',
    'getMangledName',
    'isDefaultValueAvailable',
    'hasDefaultValue',
] as $method) {
    echo $method, '=', method_exists(ReflectionProperty::class, $method) ? '1' : '0', "\n";
}
--EXPECT--
getRawValue=1
setRawValue=1
getMangledName=0
isDefaultValueAvailable=0
hasDefaultValue=1
