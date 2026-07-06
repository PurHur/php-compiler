<?php
declare(strict_types=1);

$php_compiler_llvm_dl = null;

function global_foreach_try_after_early_return(): void
{
    if (!extension_loaded('ffi')) {
        return;
    }
    global $php_compiler_llvm_dl;
    foreach (['a', 'b'] as $lib) {
        try {
            $php_compiler_llvm_dl = 42;
            break;
        } catch (\Exception $e) {
            continue;
        }
    }
}
