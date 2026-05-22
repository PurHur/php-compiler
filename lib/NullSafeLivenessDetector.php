<?php

declare(strict_types=1);

namespace PHPCompiler;

use PHPCfg\Block;
use PHPCfg\Func;
use PHPCfg\LivenessDetector;

/**
 * Skip liveness for declaration-only functions (interfaces, abstract stubs) with no CFG.
 */
final class NullSafeLivenessDetector extends LivenessDetector
{
    protected function detectFunc(Func $func): void
    {
        if (!$func->cfg instanceof Block) {
            return;
        }

        parent::detectFunc($func);
    }
}
