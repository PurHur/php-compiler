<?php

declare(strict_types=1);

namespace PHPCompiler\ext\dom;

use PHPCompiler\JIT\BasicBlockHelper;
use PHPCompiler\JIT\Context;
use PHPCompiler\JIT\JitStringBuiltinArg;
use PHPCompiler\JIT\JitValueBox;
use PHPCompiler\JIT\Variable as JITVariable;
use PHPCompiler\VM\Builtin\VmClassMethod;
use PHPLLVM\Value;

/**
 * User-script AOT for DOMImplementation::createDocument().
 *
 * Thin standalone NestedJIT of {@see VmDom::createDocument} SIGSEGVs after
 * c:main_before_php (empty DomRegistry / uninit documentElement). Fold
 * compile-time namespace + qualifiedName into a main-module DOMDocument
 * with a pinned documentElement (peer {@see JitDomXmlDocumentCreateFromString}).
 *
 * php-src: ext/dom/php_dom.c PHP_METHOD(DOMImplementation, createDocument)
 *          xmlNewDoc + xmlNewDocNode + xmlDocSetRootElement (#32531)
 *          Non-null \$doctype adopted as \$doc->doctype (#35182).
 */
final class JitDomCreateDocument
{
    private const CLASS_DOCUMENT = 'DOMDocument';

    private const CLASS_ELEMENT = 'DOMElement';

    public static function invoke(Context $context, JITVariable ...$args): Value
    {
        BasicBlockHelper::ensureOpenInsertBlock($context, 'dom_impl_createdocument_cont');
        if (!VmClassMethod::requireJitUserArgCountRange(
            $context,
            $args,
            'DOMImplementation::createDocument',
            0,
            3
        )) {
            return VmClassMethod::jitArgcDummyReturn($context);
        }

        if (isset($args[2])
            && $context->callerStrictTypes
            && (JITVariable::TYPE_NULL === $args[2]->type || $args[2]->isNullConstant)
        ) {
            \PHPCompiler\JIT\JitNativeString::ensureInsertBlock($context);
            \PHPCompiler\JIT\ExceptionBridge::emitTypeErrorAndAbort(
                $context,
                'DOMImplementation::createDocument(): Argument #2 ($qualifiedName) must be of type string, null given'
            );

            return VmClassMethod::jitArgcDummyReturn($context);
        }

        $namespace = self::compileTimeNamespace($args[1] ?? null);
        $qualifiedName = self::compileTimeQualifiedName($args[2] ?? null);
        if (false === $namespace || false === $qualifiedName) {
            throw new \LogicException(
                'DOMImplementation::createDocument() user-script AOT requires compile-time namespace/qualifiedName'
            );
        }
        $doctypeArg = self::optionalDoctypeArg($args[3] ?? null);

        $objectType = $context->type->object;
        $docClassId = $objectType->lookup(self::CLASS_DOCUMENT);
        if (!$objectType->hasProperty($docClassId, VmDom::PROP_DOCUMENT_ELEMENT)) {
            $objectType->defineProperty($docClassId, VmDom::PROP_DOCUMENT_ELEMENT, JITVariable::TYPE_OBJECT);
        }

        $document = $objectType->allocate($docClassId);
        $objectType->markObjectConstructed($document);
        // Unset NATIVE_LONG nodeType SIGSEGVs on $doc->nodeType (#35173 leftover of #35168).
        JitDomCreateElement::storeNodeType(
            $context,
            $document,
            self::CLASS_DOCUMENT,
            DomConstants::XML_DOCUMENT_NODE
        );

        if ('' === $qualifiedName) {
            self::storeNullDocumentElement($context, $document);
            if (null !== $doctypeArg) {
                self::attachDoctype($context, $document, $doctypeArg);
            } else {
                self::storeNullDoctype($context, $document);
            }

            return self::boxObjectResult($context, $document);
        }

        $element = self::materializeRoot($context, $namespace, $qualifiedName);
        JitDomCreateElement::storeUserScriptInnerXml($context, $element, '');
        JitDomGetNodePath::storeOn($context, $element, self::CLASS_ELEMENT, '/'.$qualifiedName);

        $xml = self::rootXmlLiteral($namespace, $qualifiedName);
        JitDomDocumentElement::syncChildrenFromXmlPublic($context, $element, $xml, '/'.$qualifiedName);

        $elemJit = new JITVariable(
            $context,
            JITVariable::TYPE_OBJECT,
            JITVariable::KIND_VALUE,
            $element
        );
        $objectType->propertyStore(
            $objectType->propertySlotFor($document, self::CLASS_DOCUMENT, VmDom::PROP_DOCUMENT_ELEMENT),
            $elemJit,
            JITVariable::TYPE_OBJECT
        );

        $elementClassId = $objectType->lookup(self::CLASS_ELEMENT);
        if (!$objectType->hasProperty($elementClassId, VmDom::PROP_PARENT_NODE)) {
            $objectType->defineProperty($elementClassId, VmDom::PROP_PARENT_NODE, JITVariable::TYPE_VALUE);
        }
        $docJit = new JITVariable(
            $context,
            JITVariable::TYPE_OBJECT,
            JITVariable::KIND_VALUE,
            $document
        );
        $objectType->propertyStore(
            $objectType->propertySlotFor($element, self::CLASS_ELEMENT, VmDom::PROP_PARENT_NODE),
            $docJit,
            JITVariable::TYPE_VALUE
        );

        DomUserScriptPinnedRootLlvm::pin($context, $element);

        // php-src createDocument adopts $doctype as intSubset / $doc->doctype (#35182).
        if (null !== $doctypeArg) {
            self::attachDoctype($context, $document, $doctypeArg);
        } else {
            // Unset PROP_DOCTYPE slot reads as non-null under detachedFetch (#35182).
            self::storeNullDoctype($context, $document);
        }

        return self::boxObjectResult($context, $document);
    }

    /**
     * null = omit / explicit null; JITVariable = DocumentType stand-in to adopt (#35182).
     */
    private static function optionalDoctypeArg(?JITVariable $arg): ?JITVariable
    {
        if (null === $arg || JITVariable::TYPE_NULL === $arg->type || $arg->isNullConstant) {
            return null;
        }
        if (JITVariable::TYPE_OBJECT !== $arg->type && JITVariable::TYPE_VALUE !== $arg->type) {
            throw new \LogicException(
                'DOMImplementation::createDocument() user-script AOT $doctype must be DOMDocumentType or null'
            );
        }

        return $arg;
    }

    /**
     * Pin $doc->doctype + saveXML <!DOCTYPE> stamp (peer loadXML storeDoctypeProperty).
     *
     * php-src: ext/dom/php_dom.c — createDocument adopts doctype via xmlCreateIntSubset.
     */
    private static function attachDoctype(Context $context, Value $document, JITVariable $doctypeArg): void
    {
        $doctype = self::loadObjectArg($context, $doctypeArg);
        $objectType = $context->type->object;
        $docClassId = $objectType->lookup(self::CLASS_DOCUMENT);
        if (!$objectType->hasProperty($docClassId, VmDom::PROP_DOCTYPE)) {
            $objectType->defineProperty($docClassId, VmDom::PROP_DOCTYPE, JITVariable::TYPE_VALUE);
        }
        $doctypeJit = new JITVariable(
            $context,
            JITVariable::TYPE_OBJECT,
            JITVariable::KIND_VALUE,
            $doctype
        );
        $objectType->propertyStore(
            $objectType->propertySlotFor($document, self::CLASS_DOCUMENT, VmDom::PROP_DOCTYPE),
            $doctypeJit,
            JITVariable::TYPE_VALUE
        );

        $standInClass = 'DOMElement';
        $standInId = $objectType->lookup($standInClass);
        if (!$objectType->hasProperty($standInId, VmDom::PROP_PARENT_NODE)) {
            $objectType->defineProperty($standInId, VmDom::PROP_PARENT_NODE, JITVariable::TYPE_VALUE);
        }
        $docJit = new JITVariable(
            $context,
            JITVariable::TYPE_OBJECT,
            JITVariable::KIND_VALUE,
            $document
        );
        $objectType->propertyStore(
            $objectType->propertySlotFor($doctype, $standInClass, VmDom::PROP_PARENT_NODE),
            $docJit,
            JITVariable::TYPE_VALUE
        );

        // createDocumentType already called rememberCreate; mark attached for $doc->doctype /
        // saveXML prefix (#34887 / #33584).
        DomUserScriptDoctypeLlvm::markAttached();
    }

    private static function loadObjectArg(Context $context, JITVariable $arg): Value
    {
        if (JITVariable::TYPE_OBJECT === $arg->type) {
            return $context->helper->loadValue($arg);
        }
        if (JITVariable::TYPE_VALUE === $arg->type) {
            return $context->builder->call(
                $context->lookupFunction('__value__readObject'),
                JitValueBox::valuePtrFromVariable($context, $arg)
            );
        }

        throw new \LogicException(
            'DOMImplementation::createDocument() $doctype must be an object'
        );
    }

    private static function materializeRoot(
        Context $context,
        ?string $namespace,
        string $qualifiedName
    ): Value {
        if (null !== $namespace && '' !== $namespace) {
            return JitDomCreateElementNS::materializeElementNSFromLiterals(
                $context,
                $namespace,
                $qualifiedName,
                ''
            );
        }

        return JitDomCreateElement::materializeElementWithTextContent($context, $qualifiedName, '');
    }

    /** xmlNodeDump of the createDocument root (php-src xmlNewDocNode + nsDef). */
    private static function rootXmlLiteral(?string $namespace, string $qualifiedName): string
    {
        if (null === $namespace || '' === $namespace) {
            return '<'.$qualifiedName.'/>';
        }
        $pos = strpos($qualifiedName, ':');
        if (false === $pos) {
            return '<'.$qualifiedName.' xmlns="'.htmlspecialchars($namespace, ENT_XML1).'"/>';
        }
        $prefix = substr($qualifiedName, 0, $pos);

        return '<'.$qualifiedName.' xmlns:'.$prefix.'="'.htmlspecialchars($namespace, ENT_XML1).'"/>';
    }

    /** @return string|false|null false = dynamic */
    private static function compileTimeNamespace(?JITVariable $arg): string|false|null
    {
        if (null === $arg || JITVariable::TYPE_NULL === $arg->type || $arg->isNullConstant) {
            return null;
        }
        $lit = JitStringBuiltinArg::compileTimeLiteral($arg) ?? $arg->compileTimeString;
        if (null === $lit) {
            return false;
        }

        return $lit;
    }

    /** @return string|false false = dynamic */
    private static function compileTimeQualifiedName(?JITVariable $arg): string|false
    {
        if (null === $arg || JITVariable::TYPE_NULL === $arg->type || $arg->isNullConstant) {
            return '';
        }
        $lit = JitStringBuiltinArg::compileTimeLiteral($arg) ?? $arg->compileTimeString;
        if (null === $lit) {
            return false;
        }

        return $lit;
    }

    private static function storeNullDocumentElement(Context $context, Value $document): void
    {
        $slot = JitValueBox::alloc($context);
        $context->builder->call(
            $context->lookupFunction('__value__writeNull'),
            JitValueBox::pointer($context, $slot)
        );
        $propVar = new JITVariable($context, JITVariable::TYPE_VALUE, JITVariable::KIND_VARIABLE, $slot);
        $context->type->object->propertyStore(
            $context->type->object->propertySlotFor(
                $document,
                self::CLASS_DOCUMENT,
                VmDom::PROP_DOCUMENT_ELEMENT
            ),
            $propVar,
            JITVariable::TYPE_NULL
        );
    }

    /** Explicit null so detachedFetch does not read an unset PROP_DOCTYPE slot (#35182). */
    private static function storeNullDoctype(Context $context, Value $document): void
    {
        $objectType = $context->type->object;
        $docClassId = $objectType->lookup(self::CLASS_DOCUMENT);
        if (!$objectType->hasProperty($docClassId, VmDom::PROP_DOCTYPE)) {
            $objectType->defineProperty($docClassId, VmDom::PROP_DOCTYPE, JITVariable::TYPE_VALUE);
        }
        $slot = JitValueBox::alloc($context);
        $context->builder->call(
            $context->lookupFunction('__value__writeNull'),
            JitValueBox::pointer($context, $slot)
        );
        $propVar = new JITVariable($context, JITVariable::TYPE_VALUE, JITVariable::KIND_VARIABLE, $slot);
        $objectType->propertyStore(
            $objectType->propertySlotFor($document, self::CLASS_DOCUMENT, VmDom::PROP_DOCTYPE),
            $propVar,
            JITVariable::TYPE_NULL
        );
    }

    private static function boxObjectResult(Context $context, Value $object): Value
    {
        $slot = JitValueBox::alloc($context);
        $ptr = JitValueBox::pointer($context, $slot);
        $context->builder->call(
            $context->lookupFunction('__value__writeObject'),
            $ptr,
            $object
        );

        return JitValueBox::normalizeValuePtr($context, $ptr);
    }
}
