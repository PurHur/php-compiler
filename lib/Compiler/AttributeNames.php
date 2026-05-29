<?php

declare(strict_types=1);

namespace PHPCompiler\Compiler;

use PHPCfg\Op;

/**
 * Extract declared PHP 8 attribute class names from CFG op metadata (#1936).
 */
final class AttributeNames
{
    /**
     * @return list<string> Fully-qualified attribute names as written in source.
     */
    public static function fromOp(Op $op): array
    {
        return AttributeEntry::namesFromList(AttributeMetadata::fromOp($op));
    }
}
