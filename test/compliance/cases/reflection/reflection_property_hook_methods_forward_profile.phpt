--TEST--
ReflectionProperty hook/lazy/final APIs on 8.4 forward profile (#17493, #20511, #22309, ext/reflection/php_reflection.c)
--ENV--
PHP_COMPILER_PROFILE=8.4
--FILE--
<?php
foreach (['hasHook', 'hasHooks', 'getHook', 'getHooks', 'setHook', 'skipLazyInitialization', 'isLazy', 'isFinal', 'isAbstract', 'isVirtual'] as $method) {
    echo $method, '=', method_exists(ReflectionProperty::class, $method) ? 'yes' : 'no', "\n";
}
--EXPECT--
hasHook=yes
hasHooks=yes
getHook=yes
getHooks=yes
setHook=no
skipLazyInitialization=yes
isLazy=yes
isFinal=yes
isAbstract=yes
isVirtual=yes
