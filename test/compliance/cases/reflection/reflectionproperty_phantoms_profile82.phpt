--TEST--
ReflectionProperty phantoms absent on PROFILE=8.2 (#22601, ext/reflection/php_reflection.stub.php)
--ENV--
PHP_COMPILER_PROFILE=8.2
--FILE--
<?php
class RpPhantomC {
    public int $x = 1;
}
foreach ([
    'getRawValue',
    'setRawValue',
    'getMangledName',
    'isDefaultValueAvailable',
    'hasDefaultValue',
] as $method) {
    echo $method, '=', method_exists(ReflectionProperty::class, $method) ? '1' : '0', "\n";
}
try {
    (new ReflectionProperty(RpPhantomC::class, 'x'))->getMangledName();
    echo "call=ok\n";
} catch (Error $e) {
    echo 'call=', str_contains($e->getMessage(), 'getMangledName') ? 'undefined' : $e->getMessage(), "\n";
}
--EXPECT--
getRawValue=0
setRawValue=0
getMangledName=0
isDefaultValueAvailable=0
hasDefaultValue=1
call=undefined
