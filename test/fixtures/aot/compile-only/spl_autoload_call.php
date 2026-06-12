<?php
// Compile-only (#3486): spl_autoload_call() JIT/AOT lowering via __phpc_spl_autoload_dispatch.
$loaded = false;
spl_autoload_register(function (string $class) use (&$loaded): void {
    if ('LazyClass' !== $class) {
        return;
    }
    class LazyClass
    {
        public function id(): int
        {
            return 7;
        }
    }
    $loaded = true;
});
spl_autoload_call('LazyClass');
echo (int) $loaded, "\n";
