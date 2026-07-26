<?php

declare(strict_types=1);

namespace PHPCompiler\ext\dom;

use PHPCompiler\ext\dom\JitDomDocumentMethodKernel;
use PHPCompiler\JIT\Builtin\Type\Object_;
use PHPCompiler\JIT\Builtin\Type\ObjectInstancePropertyLlvm;
use PHPCompiler\JIT\Variable as JITVariable;
use PHPLLVM\Value;

/** LLVM lowering for live DOMNodeList::$length in user-script AOT (#18478, #19208). */
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
        $context = $objectType->jitContext();
        // Tag-list GLOBAL_COUNT is only valid for pure ID-map / user-script live lists.
        // After full DomLoadXMLRuntime, childNodes and query lists use DomRegistry slots (#23251).
        if (JitDomDocumentMethodKernel::shouldUse($context)
            && JitDomLoadXMLUserScript::lastLoadWasPureUserScript()
            && null !== $context->module->getNamedGlobal(DomUserScriptLiveTagListLlvm::GLOBAL_COUNT)
        ) {
            return new JITVariable(
                $context,
                JITVariable::TYPE_NATIVE_LONG,
                JITVariable::KIND_VALUE,
                DomUserScriptLiveTagListLlvm::readStoredCount($context)
            );
        }

        return ObjectInstancePropertyLlvm::propertyFetchDeclaredSlot(
            $objectType,
            $obj,
            self::CLASS_NODELIST,
            self::PROP_LENGTH,
            $objectType->lookup(self::CLASS_NODELIST)
        );
    }
}
