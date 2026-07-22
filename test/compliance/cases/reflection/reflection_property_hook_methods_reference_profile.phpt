--TEST--
ReflectionProperty hook/lazy/final APIs phantom on 8.2 reference profile (#17493, #20511, #22309, ext/reflection/php_reflection.c)
--FILE--
<?php
foreach (['hasHook', 'hasHooks', 'getHook', 'getHooks', 'setHook', 'skipLazyInitialization', 'isLazy', 'isFinal', 'isAbstract', 'isVirtual'] as $method) {
    echo $method, '=', method_exists(ReflectionProperty::class, $method) ? 'yes' : 'no', "\n";
}
--EXPECT--
hasHook=no
hasHooks=no
getHook=no
getHooks=no
setHook=no
skipLazyInitialization=no
isLazy=no
isFinal=no
isAbstract=no
isVirtual=no
