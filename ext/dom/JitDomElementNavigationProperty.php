<?php

declare(strict_types=1);

namespace PHPCompiler\ext\dom;

use PHPCompiler\JIT\Builtin\DomDocumentMethodUserScriptLlvm;
use PHPCompiler\JIT\Builtin\DomNodeChildPropertyRuntime;
use PHPCompiler\JIT\Builtin\Type\Object_;
use PHPCompiler\JIT\Builtin\Type\ObjectInstancePropertyLlvm;
use PHPCompiler\JIT\BasicBlockHelper;
use PHPCompiler\JIT\JitValueBox;
use PHPCompiler\JIT\Variable as JITVariable;
use PHPLLVM\Value;

/**
 * LLVM lowering for ParentNode / NonDocumentTypeChildNode element-navigation props (#19431).
 *
 * User-script AOT reads declared slots (kept in sync by DomNodeLiveMutationRuntime).
 * Other modes call DomRegistry helpers for live truth.
 */
final class JitDomElementNavigationProperty
{
    private const CLASS_ELEMENT = 'DOMElement';

    /** @var list<string> */
    private const PROPS = [
        'firstelementchild',
        'lastelementchild',
        'childelementcount',
        'nextelementsibling',
        'previouselementsibling',
    ];

    public static function isElementNavigationProperty(string $classLc, string $propLc): bool
    {
        if (!str_starts_with(strtolower($classLc), 'dom')) {
            return false;
        }

        return \in_array(strtolower($propLc), self::PROPS, true);
    }

    public static function fetch(Object_ $objectType, Value $obj, string $propName): JITVariable
    {
        $context = $objectType->jitContext();
        $propLc = strtolower($propName);

        // User-script AOT: declared slots mirrored after mutations (#18951 pattern).
        if (DomDocumentMethodUserScriptLlvm::shouldUse($context)) {
            $classId = $objectType->lookup(self::CLASS_ELEMENT);
            $jitType = 'childelementcount' === $propLc
                ? JITVariable::TYPE_NATIVE_LONG
                : JITVariable::TYPE_VALUE;
            if (!$objectType->hasProperty($classId, $propName)) {
                $objectType->defineProperty($classId, $propName, $jitType);
            }

            return ObjectInstancePropertyLlvm::propertyFetchDeclaredSlot(
                $objectType,
                $obj,
                self::CLASS_ELEMENT,
                $propName,
                $classId
            );
        }

        DomNodeChildPropertyRuntime::ensureLinked($context, $propName);
        BasicBlockHelper::ensureOpenInsertBlock($context, 'dom_el_nav_prop_'.$propLc);
        $result = $context->builder->call(
            $context->lookupFunction(DomNodeChildPropertyRuntime::abiFor($propName)),
            $obj
        );
        BasicBlockHelper::ensureOpenInsertBlock($context, 'dom_el_nav_prop_'.$propLc.'_done');

        if ('childelementcount' === $propLc) {
            return new JITVariable(
                $context,
                JITVariable::TYPE_NATIVE_LONG,
                JITVariable::KIND_VALUE,
                $result
            );
        }

        return new JITVariable(
            $context,
            JITVariable::TYPE_VALUE,
            JITVariable::KIND_VALUE,
            JitValueBox::normalizeValuePtr($context, $result)
        );
    }
}
