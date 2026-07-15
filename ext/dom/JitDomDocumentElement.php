<?php

declare(strict_types=1);

namespace PHPCompiler\ext\dom;

use PHPCompiler\JIT\Builtin\DomDocumentMethodUserScriptLlvm;
use PHPCompiler\JIT\Builtin\Type\Object_;
use PHPCompiler\JIT\JitValueBox;
use PHPCompiler\JIT\Variable as JITVariable;
use PHPLLVM\Value;

/** LLVM lowering for DOMDocument::$documentElement in user-script AOT (#18478). */
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
        if (!DomDocumentMethodUserScriptLlvm::shouldUse($context)) {
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

        $tag = DomParseSimpleXmlJitHelper::rootTagArgv($xml);
        $element = JitDomCreateElement::materializeElementFromLiteral($context, $tag);

        return new JITVariable(
            $context,
            JITVariable::TYPE_OBJECT,
            JITVariable::KIND_VALUE,
            $element
        );
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
