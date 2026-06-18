<?php

declare(strict_types=1);

namespace PHPCompiler\ext\standard;

use PHPCompiler\Web\DeployRoot;

/**
 * Lowered into JIT/AOT modules for __compiler_phpc_deploy_path (#9309, php-in-PHP).
 *
 * SSOT: {@see DeployRoot::resolvePath()} / {@see phpc_deploy_path} VM builtin.
 */
final class DeployPathJitHelper
{
    public static function resolve(string $relFromProjectRoot, string $fallbackAbsoluteDir): string
    {
        return DeployRoot::resolvePath($relFromProjectRoot, $fallbackAbsoluteDir);
    }
}
