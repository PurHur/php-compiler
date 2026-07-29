--TEST--
ReflectionMethod getDeprecated* + ReflectionFiber::getExecutingFiber phantoms on PROFILE=8.2 (#25058)
--ENV--
PHP_COMPILER_PROFILE=8.2
--FILE--
<?php
class C { function m() {} }
$m = new ReflectionMethod('C', 'm');
echo 'getDeprecatedMessage=', method_exists($m, 'getDeprecatedMessage') ? '1' : '0', "\n";
echo 'getDeprecatedVersion=', method_exists($m, 'getDeprecatedVersion') ? '1' : '0', "\n";
echo 'getExecutingFiber=', method_exists('ReflectionFiber', 'getExecutingFiber') ? '1' : '0', "\n";
try {
    $m->getDeprecatedMessage();
    echo "call=ok\n";
} catch (Error $e) {
    echo 'call=', str_contains($e->getMessage(), 'getDeprecatedMessage') ? 'undefined' : $e->getMessage(), "\n";
}
--EXPECT--
getDeprecatedMessage=0
getDeprecatedVersion=0
getExecutingFiber=0
call=undefined
