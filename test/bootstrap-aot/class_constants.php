<?php

declare(strict_types=1);

/**
 * Bootstrap AOT lint: class constants (issue #84 / Const_ lowering).
 */

namespace BootstrapAot;

class Codes
{
    public const ANSWER = 42;
    public const LABEL = 'ok';
}

// Lint gate: declare only (ClassConstFetch is separate from Const_ lowering).
echo "ok\n";
