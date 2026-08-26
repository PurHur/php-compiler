<?php

declare(strict_types=1);

namespace PHPCompiler\ext\dom;

use PHPCompiler\ext\dom\JitDomDocumentMethodKernel;
use PHPCompiler\JIT\Builtin\DomNodeChildPropertyRuntime;
use PHPCompiler\JIT\Builtin\Type\Object_;
use PHPCompiler\JIT\Builtin\Type\ObjectInstancePropertyLlvm;
use PHPCompiler\JIT\BasicBlockHelper;
use PHPCompiler\JIT\JitValueBox;
use PHPCompiler\JIT\Variable as JITVariable;
use PHPLLVM\Builder;
use PHPLLVM\Value;

/**
 * LLVM lowering for ParentNode / NonDocumentTypeChildNode element-navigation props (#19431).
 *
 * User-script AOT reads declared slots (kept in sync by DomNodeLiveMutationRuntime).
 * Other modes call DomRegistry helpers for live truth.
 *
 * Document receivers must not use DOMElement property indices — that SIGSEGVs (#34910).
 * For Document, first/lastElementChild ≡ documentElement; childElementCount is 0 or 1
 * (php-src parentnode.c / document can have at most one element child).
 *
 * Element-receiver FEC/LEC/NES/PES stamp compile-time tag/index so importNode recovers
 * the right element after ARG_SEND drops Variable metadata (#35017 / #35021 / peer #33918).
 */
final class JitDomElementNavigationProperty
{
    private const CLASS_ELEMENT = 'DOMElement';

    private const CLASS_DOCUMENT = 'DOMDocument';

    /** @var list<string> */
    private const PARENT_NODE_PROPS = [
        'firstelementchild',
        'lastelementchild',
        'childelementcount',
    ];

    /** @var list<string> */
    private const SIBLING_PROPS = [
        'nextelementsibling',
        'previouselementsibling',
    ];

    public static function isElementNavigationProperty(string $classLc, string $propLc): bool
    {
        $classLc = strtolower(str_replace('/', '\\', ltrim($classLc, '\\')));
        $propLc = strtolower($propLc);
        if (!str_starts_with($classLc, 'dom')) {
            return false;
        }
        if (\in_array($propLc, self::PARENT_NODE_PROPS, true)) {
            return true;
        }
        // NonDocumentTypeChildNode — not on Document.
        if (\in_array($propLc, self::SIBLING_PROPS, true)) {
            return 'domdocument' !== $classLc
                && 'dom\\document' !== $classLc
                && 'dom\\xmldocument' !== $classLc;
        }

        return false;
    }

    public static function fetch(
        Object_ $objectType,
        Value $obj,
        string $propName,
        string $className = self::CLASS_ELEMENT,
        ?JITVariable $receiverVar = null
    ): JITVariable {
        $context = $objectType->jitContext();
        $propLc = strtolower($propName);
        $classLc = strtolower(str_replace('/', '\\', ltrim($className, '\\')));

        if (JitDomDocumentMethodKernel::shouldUse($context)
            && ('domdocument' === $classLc
                || 'dom\\document' === $classLc
                || 'dom\\xmldocument' === $classLc)
        ) {
            return self::fetchDocumentParentNode($objectType, $obj, $propLc, $className);
        }

        // User-script AOT: declared slots mirrored after mutations (#18951 pattern).
        if (JitDomDocumentMethodKernel::shouldUse($context)) {
            $classId = $objectType->lookup(self::CLASS_ELEMENT);
            $jitType = 'childelementcount' === $propLc
                ? JITVariable::TYPE_NATIVE_LONG
                : JITVariable::TYPE_VALUE;
            if (!$objectType->hasProperty($classId, $propName)) {
                $objectType->defineProperty($classId, $propName, $jitType);
            }

            $result = ObjectInstancePropertyLlvm::propertyFetchDeclaredSlot(
                $objectType,
                $obj,
                self::CLASS_ELEMENT,
                $propName,
                $classId
            );
            // Stamp element-child / element-sibling compile-time metadata so
            // importNode / cloneNode recover the right node after ARG_SEND
            // (#35017 FEC/LEC; #35021 NES/PES).
            if ('firstelementchild' === $propLc || 'lastelementchild' === $propLc) {
                $result->classUserType = self::CLASS_ELEMENT;
                JitDomNodeChildProperty::annotateCompileTimeElementChild(
                    $result,
                    $propName,
                    $receiverVar
                );
            } elseif ('nextelementsibling' === $propLc || 'previouselementsibling' === $propLc) {
                $result->classUserType = self::CLASS_ELEMENT;
                JitDomNodeChildProperty::annotateCompileTimeElementSibling(
                    $result,
                    $propName,
                    $receiverVar
                );
            }

            return $result;
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

    /**
     * Document ParentNode: at most one element child (= documentElement).
     * php-src ext/dom/parentnode.c
     */
    private static function fetchDocumentParentNode(
        Object_ $objectType,
        Value $obj,
        string $propLc,
        string $className
    ): JITVariable {
        $context = $objectType->jitContext();
        if ('firstelementchild' === $propLc || 'lastelementchild' === $propLc) {
            return JitDomDocumentElement::fetch($objectType, $obj, null, $className);
        }

        // childElementCount: 1 if documentElement non-null, else 0.
        $docEl = JitDomDocumentElement::fetch($objectType, $obj, null, $className);
        $objPtrTy = $context->getTypeFromString('__object__*');
        $i64 = $context->getTypeFromString('int64');
        $ptr = JITVariable::KIND_VALUE === $docEl->kind
            ? $docEl->value
            : $context->builder->load($docEl->value);
        // documentElement fetch may return TYPE_VALUE boxed object — normalize.
        if (JITVariable::TYPE_VALUE === $docEl->type) {
            $valuePtr = JitValueBox::normalizeValuePtr($context, $ptr);
            $ptr = $context->builder->call(
                $context->lookupFunction('__value__readObject'),
                $valuePtr
            );
        }
        $isNull = $context->builder->icmp(Builder::INT_EQ, $ptr, $objPtrTy->constNull());
        $count = $context->builder->select(
            $isNull,
            $i64->constInt(0, false),
            $i64->constInt(1, false)
        );

        return new JITVariable(
            $context,
            JITVariable::TYPE_NATIVE_LONG,
            JITVariable::KIND_VALUE,
            $count
        );
    }
}
