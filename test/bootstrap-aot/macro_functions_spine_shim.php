<?php

declare(strict_types=1);

/**
 * Spine-safe macro_functions substitute (issues #2126, #113).
 *
 * Real src/macro_functions.php registers Yay macros for php-llvm FFI parsing.
 * Self-host compiler_lib_spine_smoke does not lower Yay DSL at link time.
 */

namespace Yay;

if (!\class_exists('Yay\\Parser', false)) {
    /** @internal stub for spine type closure only */
    final class Parser
    {
    }
}
