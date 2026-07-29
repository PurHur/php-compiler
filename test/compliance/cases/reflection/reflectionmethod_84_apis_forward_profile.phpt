--TEST--
ReflectionMethod getDeprecated* on PROFILE=8.4; getExecutingFiber still absent (#25058)
--ENV--
PHP_COMPILER_PROFILE=8.4
--FILE--
<?php
class C {
    #[\Deprecated(message: 'Old entry', since: '8.4')]
    function m() {}
}
$m = new ReflectionMethod('C', 'm');
echo 'getDeprecatedMessage=', method_exists($m, 'getDeprecatedMessage') ? '1' : '0', "\n";
echo 'getDeprecatedVersion=', method_exists($m, 'getDeprecatedVersion') ? '1' : '0', "\n";
echo 'getExecutingFiber=', method_exists('ReflectionFiber', 'getExecutingFiber') ? '1' : '0', "\n";
if (method_exists($m, 'getDeprecatedMessage')) {
    echo $m->getDeprecatedMessage(), "\n";
    echo $m->getDeprecatedVersion(), "\n";
}
--EXPECT--
getDeprecatedMessage=1
getDeprecatedVersion=1
getExecutingFiber=0
Old entry
8.4
