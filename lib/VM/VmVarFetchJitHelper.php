<?php

declare(strict_types=1);

namespace PHPCompiler\VM;

use PHPCompiler\ext\standard\SuperglobalNames;

/**
 * Minimal JIT/AOT slice for {@see VmVarFetch::isSuperglobalName} (#10289).
 *
 * Full VmVarFetch.php must not be nested-JIT'd during Context init — operandBindingRank
 * hits bool→object assign (issue #8708 inventory argv link).
 */
final class VmVarFetchJitHelper
{
    public static function isSuperglobalName(string $name): bool
    {
        return SuperglobalNames::isSuperglobalName($name);
    }
}
