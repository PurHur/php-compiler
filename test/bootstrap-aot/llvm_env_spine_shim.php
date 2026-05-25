<?php

declare(strict_types=1);

/**
 * Spine-safe llvm-env substitute (issues #2126, #1056).
 *
 * Full src/llvm-env.php uses FFI dlopen(RTLD_GLOBAL) and breaks LLVM 9 self-host link.
 * Self-host bundles only need PATH/LD_LIBRARY_PATH hints when the host already set them.
 */

if (!\function_exists('php_compiler_preload_llvm_deps')) {
    /**
     * @param list<string> $names
     */
    function php_compiler_preload_llvm_deps(string $dir, array $names): void
    {
    }
}
