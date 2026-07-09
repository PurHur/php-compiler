--TEST--
ReflectionProperty hook/lazy APIs on 8.4 forward profile (#17493, ext/reflection/php_reflection.c)
--ENV--
PHP_COMPILER_PROFILE=8.4
--FILE--
<?php
foreach (['hasHook', 'hasHooks', 'getHook', 'getHooks', 'skipLazyInitialization', 'isLazy'] as $method) {
    echo $method, '=', method_exists(ReflectionProperty::class, $method) ? 'yes' : 'no', "\n";
}
--EXPECT--
hasHook=yes
hasHooks=yes
getHook=yes
getHooks=yes
skipLazyInitialization=yes
isLazy=yes
