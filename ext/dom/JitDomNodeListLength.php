<?php

declare(strict_types=1);

namespace PHPCompiler\ext\dom;

use PHPCompiler\JIT\Builtin\Type\Object_;
use PHPCompiler\JIT\Builtin\Type\ObjectInstancePropertyLlvm;
use PHPCompiler\JIT\Variable as JITVariable;
use PHPLLVM\Value;

/** LLVM lowering for live DOMNodeList::$length in user-script AOT (#18478). */
final class JitDomNodeListLength
{
    private const CLASS_NODELIST = 'DOMNodeList';

    private const PROP_LENGTH = 'length';

    public static function isDomNodeListLength(string $classLc, string $propLc): bool
    {
        return 'domnodelist' === strtolower($classLc) && self::PROP_LENGTH === strtolower($propLc);
    }

    public static function fetch(Object_ $objectType, Value $obj): JITVariable
    {
        return ObjectInstancePropertyLlvm::propertyFetchDeclaredSlot(
            $objectType,
            $obj,
            self::CLASS_NODELIST,
            self::PROP_LENGTH,
            $objectType->lookup(self::CLASS_NODELIST)
        );
    }
}
