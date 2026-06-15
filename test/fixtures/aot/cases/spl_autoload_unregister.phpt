--TEST--
AOT spl_autoload_unregister() removes registered function autoload (issue #3580)
--FILE--
<?php
function lazy_unload_autoload(string $class): void
{
    if ('LazyUnload' !== $class) {
        return;
    }
    class LazyUnload {}
}
spl_autoload_register('lazy_unload_autoload');
echo (int) spl_autoload_unregister('lazy_unload_autoload'), "\n";
echo (int) spl_autoload_unregister('lazy_unload_autoload'), "\n";
--EXPECT--
1
0
