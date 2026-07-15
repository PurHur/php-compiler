<?php

declare(strict_types=1);

namespace PHPCompiler\ext\dom;

use PHPCompiler\JIT\Builtin\Type\Object_;
use PHPCompiler\JIT\Builtin\Type\ObjectInstancePropertyLlvm;
use PHPCompiler\JIT\Variable as JITVariable;
use PHPLLVM\Value;

/** LLVM lowering for DOMNode::$firstChild / $lastChild after live mutation (#18951). */
final class JitDomNodeChildProperty
{
    private const CLASS_NODE = 'DOMNode';

    public static function isDomNodeChildProperty(string $classLc, string $propLc): bool
    {
        if (!str_starts_with(strtolower($classLc), 'dom')) {
            return false;
        }

        return \in_array(strtolower($propLc), ['firstchild', 'lastchild'], true);
    }

    public static function fetch(Object_ $objectType, Value $obj, string $propName): JITVariable
    {
        return ObjectInstancePropertyLlvm::propertyFetchDeclaredSlot(
            $objectType,
            $obj,
            self::CLASS_NODE,
            $propName,
            $objectType->lookup(self::CLASS_NODE)
        );
    }
}
