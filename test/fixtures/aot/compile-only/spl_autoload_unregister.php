<?php
// Compile-only (#3580): spl_autoload_unregister() JIT/AOT lowering via __phpc_spl_autoload_unregister_apply.
function lazy_unload_autoload(string $class): void
{
    if ('LazyUnload' !== $class) {
        return;
    }
    class LazyUnload {}
}
spl_autoload_register('lazy_unload_autoload');
echo (int) spl_autoload_unregister('lazy_unload_autoload'), "\n";
