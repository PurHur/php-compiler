<?php

declare(strict_types=1);

namespace PHPCompiler\ext\dom;

use PHPCompiler\ext\dom\JitDomDocumentMethodKernel;
use PHPCompiler\JIT\Builtin\Type\Object_;
use PHPCompiler\JIT\BasicBlockHelper;
use PHPCompiler\JIT\JitValueBox;
use PHPCompiler\JIT\Variable as JITVariable;
use PHPLLVM\Value;

/** LLVM lowering for DOMDocument::$documentElement in user-script AOT (#18478, #19455). */
final class JitDomDocumentElement
{
    private const CLASS_DOCUMENT = 'DOMDocument';

    private const CLASS_ELEMENT = 'DOMElement';

    private const PROP_DOCUMENT_ELEMENT = 'documentElement';

    public static function isDomDocumentElement(string $classLc, string $propLc): bool
    {
        return 'domdocument' === strtolower($classLc)
            && 'documentelement' === strtolower($propLc);
    }

    public static function fetch(Object_ $objectType, Value $obj): JITVariable
    {
        $context = $objectType->jitContext();
        if (!JitDomDocumentMethodKernel::shouldUse($context)) {
            return $objectType->propertyFetchOrdinary(
                $obj,
                self::CLASS_DOCUMENT,
                self::PROP_DOCUMENT_ELEMENT
            );
        }

        $xml = JitDomLoadXMLUserScript::lastCompileTimeXml();
        if (null === $xml) {
            return self::boxNull($context);
        }

        BasicBlockHelper::ensureOpenInsertBlock($context, 'dom_document_element_us');
        $tag = DomParseSimpleXmlJitHelper::rootTagArgv($xml);
        $element = JitDomCreateElement::materializeElementFromLiteral($context, $tag);
        self::syncFirstChildFromXml($context, $element, $xml);

        return new JITVariable(
            $context,
            JITVariable::TYPE_OBJECT,
            JITVariable::KIND_VALUE,
            $element
        );
    }

    /** Seed firstChild/lastChild slots from compile-time XML (#19455). */
    private static function syncFirstChildFromXml(
        \PHPCompiler\JIT\Context $context,
        Value $element,
        string $xml
    ): void {
        $node = DomParseSimpleXmlJitHelper::firstChildNodeArgv($xml);
        if (null === $node || 'comment' !== $node['kind']) {
            // Comment-only roots for #19455; other kinds keep prior null-slot behavior.
            return;
        }
        $child = JitDomCreateComment::materialize($context, $node['data']);
        $objectType = $context->type->object;
        $nodeClassId = $objectType->lookup('DOMNode');
        foreach ([VmDom::PROP_FIRST_CHILD, VmDom::PROP_LAST_CHILD] as $prop) {
            if (!$objectType->hasProperty($nodeClassId, $prop)) {
                $objectType->defineProperty($nodeClassId, $prop, JITVariable::TYPE_VALUE);
            }
        }
        $childJit = new JITVariable(
            $context,
            JITVariable::TYPE_OBJECT,
            JITVariable::KIND_VALUE,
            $child
        );
        foreach ([VmDom::PROP_FIRST_CHILD, VmDom::PROP_LAST_CHILD] as $prop) {
            $objectType->propertyStore(
                $objectType->propertySlotFor($element, 'DOMNode', $prop),
                $childJit,
                JITVariable::TYPE_VALUE
            );
        }
    }

    private static function boxNull(\PHPCompiler\JIT\Context $context): JITVariable
    {
        $slot = JitValueBox::alloc($context);
        $ptr = JitValueBox::pointer($context, $slot);
        $context->builder->call($context->lookupFunction('__value__writeNull'), $ptr);

        return new JITVariable(
            $context,
            JITVariable::TYPE_VALUE,
            JITVariable::KIND_VALUE,
            JitValueBox::normalizeValuePtr($context, $ptr)
        );
    }
}
