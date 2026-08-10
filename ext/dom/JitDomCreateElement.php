<?php

declare(strict_types=1);

namespace PHPCompiler\ext\dom;

use PHPCompiler\JIT\Builtin\DomCreateElementRuntime;
use PHPCompiler\ext\dom\JitDomDocumentMethodKernel;
use PHPCompiler\JIT\Context;
use PHPCompiler\JIT\JitStringBuiltinArg;
use PHPCompiler\JIT\JitValueBox;
use PHPCompiler\JIT\Variable as JITVariable;
use PHPLLVM\Value;

/**
 * LLVM lowering for DOMDocument::createElement() (#17391).
 *
 * php-src: ext/dom/document.c — dom_document_create_element
 */
final class JitDomCreateElement
{
    private const CLASS_ELEMENT = 'DOMElement';

    private const PROP_NODE_NAME = 'nodeName';

    private const PROP_TAG_NAME = 'tagName';

    private const PROP_ATTRIBUTES = 'attributes';

    private const PROP_TEXT_CONTENT = 'textContent';

    public static function invoke(Context $context, JITVariable ...$args): Value
    {
        if (\count($args) < 2) {
            throw new \LogicException('DOMDocument::createElement() expects receiver and name');
        }

        if (JitDomDocumentMethodKernel::shouldUse($context)) {
            $nameLit = self::compileTimeStringArg($args[1]);
            // Invalid literal must not silently materialize (#24804 / #20594 AOT gap).
            if (null !== $nameLit && !self::isValidXmlNameLit($nameLit)) {
                return self::invokeViaHelper($context, ...$args);
            }
            if (null !== $nameLit) {
                $obj = self::materializeElementFromLiteral($context, $nameLit);
                self::initTextContentSlot($context, $obj, $args[2] ?? null);
                self::storeOwnerAndNullParent($context, $obj, $args[0]);

                // Box like invokeViaHelper so nested appendChild(createElement()) ARG_SEND
                // receives a __value__* (#29638). Raw __object__* temps abort AOT.
                return self::boxObjectResult($context, $obj);
            }
            // Runtime name — helper applies xmlValidateName + strictErrorChecking.
            return self::invokeViaHelper($context, ...$args);
        }

        $nameLit = self::compileTimeStringArg($args[1]);
        if (null !== $nameLit) {
            return self::boxObjectResult($context, self::materializeElementFromLiteral($context, $nameLit));
        }

        return self::boxObjectResult($context, self::materializeElementFromRuntimeName($context, $args[1]));
    }

    /** Mirror VmDom::isValidXmlName for compile-time literal gating (#24804). */
    private static function isValidXmlNameLit(string $name): bool
    {
        return '' !== $name && 1 === preg_match('/^[A-Za-z_:][\w.:-]*$/', $name);
    }

    /**
     * Living Dom\Document::createElement — main-module materialize (no NestedJIT).
     * User-script createFromString docs are not DomRegistry ObjectEntries; the helper
     * path segfaults (#28958). HTMLDocument → Dom\HTMLElement + uppercase tagName.
     */
    public static function invokeLiving(
        Context $context,
        string $elementClass,
        bool $htmlUppercase,
        JITVariable ...$args
    ): Value {
        if (\count($args) < 2) {
            throw new \LogicException('Dom\\Document::createElement() expects receiver and name');
        }

        $nameLit = self::compileTimeStringArg($args[1]);
        if (null !== $nameLit) {
            if (!self::isValidXmlNameLit($nameLit)) {
                return self::invokeViaHelper($context, ...$args);
            }
            $tag = $htmlUppercase ? strtoupper($nameLit) : $nameLit;
            $obj = self::materializeElementFromLiteral($context, $tag, $elementClass);
            self::initTextContentSlot($context, $obj, $args[2] ?? null, $elementClass);
            self::storeOwnerAndNullParent($context, $obj, $args[0], $elementClass);

            return self::boxObjectResult($context, $obj);
        }

        // Runtime name — helper is document-aware but needs a real ObjectEntry document.
        return self::invokeViaHelper($context, ...$args);
    }

    /**
     * NestedJIT ABI for DOMDocument / living docs with a DomRegistry ObjectEntry (#18938).
     * Prefer {@see invokeLiving} for user-script-materialized HTMLDocument receivers (#28958).
     */
    public static function invokeViaHelper(Context $context, JITVariable ...$args): Value
    {
        DomCreateElementRuntime::ensureLinked($context);

        $document = self::loadObjectArg($context, $args[0]);
        $name = self::loadStringArg($context, $args[1]);
        $valueArg = $args[2] ?? null;
        $value = null !== $valueArg
            ? self::loadStringArg($context, $valueArg)
            : $context->builder->load($context->constantStringFromString(''));
        $element = $context->builder->call(
            $context->lookupFunction(DomCreateElementRuntime::ABI_NAME),
            $document,
            $name,
            $value
        );
        $slot = JitValueBox::alloc($context);
        $ptr = JitValueBox::pointer($context, $slot);
        $context->builder->call(
            $context->lookupFunction('__value__writeObject'),
            $ptr,
            $element
        );

        return JitValueBox::normalizeValuePtr($context, $ptr);
    }

    private static function initTextContentSlot(
        Context $context,
        Value $element,
        ?JITVariable $valueArg,
        string $className = self::CLASS_ELEMENT
    ): void {
        $objectType = $context->type->object;
        $classId = $objectType->lookup($className);
        if (!$objectType->hasProperty($classId, self::PROP_TEXT_CONTENT)) {
            $objectType->defineProperty($classId, self::PROP_TEXT_CONTENT, JITVariable::TYPE_STRING);
        }
        $lit = '';
        if (null !== $valueArg) {
            $lit = $valueArg->compileTimeString ?? JitStringBuiltinArg::compileTimeLiteral($valueArg) ?? '';
        }
        $textStr = $context->builder->load($context->constantStringFromString($lit));
        $owned = $context->builder->call(
            $context->lookupFunction('__string__separate'),
            $textStr
        );
        $propVar = new JITVariable($context, JITVariable::TYPE_STRING, JITVariable::KIND_VALUE, $owned);
        $objectType->propertyStore(
            $objectType->propertySlotFor($element, $className, self::PROP_TEXT_CONTENT),
            $propVar,
            JITVariable::TYPE_STRING
        );
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

        throw new \LogicException('DOMDocument::createElement() receiver must be an object');
    }

    private static function compileTimeStringArg(JITVariable $arg): ?string
    {
        $lit = JitStringBuiltinArg::compileTimeLiteral($arg);
        if (null !== $lit) {
            return $lit;
        }

        return $arg->compileTimeString;
    }

    public static function materializeElementFromLiteral(
        Context $context,
        string $nameLit,
        string $className = self::CLASS_ELEMENT
    ): Value {
        $objectType = $context->type->object;
        $classId = $objectType->lookup($className);
        self::ensureElementPropertyLayout($objectType, $classId);

        $obj = $objectType->allocate($classId);
        $objectType->markObjectConstructed($obj);

        $nameStr = $context->builder->load($context->constantStringFromString($nameLit));
        self::storeStringProperty($context, $obj, $className, self::PROP_NODE_NAME, $nameStr);
        self::storeStringProperty($context, $obj, $className, self::PROP_TAG_NAME, $nameStr);
        self::storeNullProperty($context, $obj, $className, self::PROP_ATTRIBUTES);
        // Always seed textContent/nodeValue — defineProperty alone leaves a null
        // __string__* and pure-user-script fetches segfault (#25475 / re-#23251).
        self::storeTextContentSlots($context, $obj, '', $className);

        return $obj;
    }

    /** User-script AOT: materialize DOMElement / Dom\Element with textContent (#18493, #25475, #27108). */
    public static function materializeElementWithTextContent(
        Context $context,
        string $tag,
        string $textContent,
        string $className = self::CLASS_ELEMENT
    ): Value {
        $obj = self::materializeElementFromLiteral($context, $tag, $className);
        self::storeTextContentSlots($context, $obj, $textContent, $className);

        return $obj;
    }

    /**
     * Raw `%__object__*` for id-map / {@see DomUserScriptElementCacheLlvm} stores (#25119, #29736).
     *
     * {@see invoke()} boxes via {@see boxObjectResult()} for the call ABI (#29638); loadHTML /
     * loadXML / compile-time getElementById must keep the unboxed pointer for hashtable +
     * global-elem stores.
     */
    public static function materializeForUserScriptDocument(
        Context $context,
        JITVariable $document,
        string $tag,
        string $textContent = '',
        string $className = self::CLASS_ELEMENT
    ): Value {
        $obj = self::materializeElementWithTextContent($context, $tag, $textContent, $className);
        self::storeOwnerAndNullParent($context, $obj, $document, $className);

        return $obj;
    }

    /** Store textContent + nodeValue string slots (empty string is a real store; #25475). */
    public static function storeTextContentSlots(
        Context $context,
        Value $element,
        string $textContent,
        string $className = self::CLASS_ELEMENT
    ): void {
        $objectType = $context->type->object;
        $classId = $objectType->lookup($className);
        foreach ([self::PROP_TEXT_CONTENT, 'nodeValue', VmDom::PROP_USER_SCRIPT_INNER_XML] as $prop) {
            if (!$objectType->hasProperty($classId, $prop)) {
                $objectType->defineProperty($classId, $prop, JITVariable::TYPE_STRING);
            }
        }
        $textStr = $context->builder->load($context->constantStringFromString($textContent));
        foreach ([self::PROP_TEXT_CONTENT, 'nodeValue'] as $prop) {
            $owned = $context->builder->call(
                $context->lookupFunction('__string__separate'),
                $textStr
            );
            $propVar = new JITVariable($context, JITVariable::TYPE_STRING, JITVariable::KIND_VALUE, $owned);
            $objectType->propertyStore(
                $objectType->propertySlotFor($element, $className, $prop),
                $propVar,
                JITVariable::TYPE_STRING
            );
        }
        // Default empty; loadXML seeds real child markup via storeUserScriptInnerXml (#26757).
        self::storeUserScriptInnerXml($context, $element, '', $className);
    }

    /** Seed/replace {@see VmDom::PROP_USER_SCRIPT_INNER_XML} for AOT saveXML (#26757 / #26765). */
    public static function storeUserScriptInnerXml(
        Context $context,
        Value $element,
        string $innerXml,
        string $className = self::CLASS_ELEMENT
    ): void {
        $objectType = $context->type->object;
        $classId = $objectType->lookup($className);
        if (!$objectType->hasProperty($classId, VmDom::PROP_USER_SCRIPT_INNER_XML)) {
            $objectType->defineProperty($classId, VmDom::PROP_USER_SCRIPT_INNER_XML, JITVariable::TYPE_STRING);
        }
        $innerStr = $context->builder->load($context->constantStringFromString($innerXml));
        $ownedInner = $context->builder->call(
            $context->lookupFunction('__string__separate'),
            $innerStr
        );
        $innerVar = new JITVariable($context, JITVariable::TYPE_STRING, JITVariable::KIND_VALUE, $ownedInner);
        $objectType->propertyStore(
            $objectType->propertySlotFor($element, $className, VmDom::PROP_USER_SCRIPT_INNER_XML),
            $innerVar,
            JITVariable::TYPE_STRING
        );
    }

    private static function materializeElementFromRuntimeName(Context $context, JITVariable $nameArg): Value
    {
        $nameStr = self::loadStringArg($context, $nameArg);
        $objectType = $context->type->object;
        $classId = $objectType->lookup(self::CLASS_ELEMENT);
        self::ensureElementPropertyLayout($objectType, $classId);

        $obj = $objectType->allocate($classId);
        $objectType->markObjectConstructed($obj);
        self::storeStringProperty($context, $obj, self::CLASS_ELEMENT, self::PROP_NODE_NAME, $nameStr);
        self::storeStringProperty($context, $obj, self::CLASS_ELEMENT, self::PROP_TAG_NAME, $nameStr);
        self::storeNullProperty($context, $obj, self::CLASS_ELEMENT, self::PROP_ATTRIBUTES);

        return $obj;
    }

    private static function ensureElementPropertyLayout(
        \PHPCompiler\JIT\Builtin\Type\Object_ $objectType,
        int $classId
    ): void {
        // Predeclare every thin-AOT slot that later lowering may defineProperty().
        // allocate() bakes prop count at IR-gen time; growing the class mid-function
        // leaves earlier objects undersized so tagName/isEqualNode reads OOB (#24973).
        foreach ([
            self::PROP_NODE_NAME => JITVariable::TYPE_STRING,
            self::PROP_TAG_NAME => JITVariable::TYPE_STRING,
            self::PROP_ATTRIBUTES => JITVariable::TYPE_VALUE,
            // #21687: contains()/getRootNode without DomRegistry.
            VmDom::PROP_PARENT_NODE => JITVariable::TYPE_VALUE,
            VmDom::PROP_OWNER_DOCUMENT => JITVariable::TYPE_VALUE,
            // Sequential appendChild sibling walks (#25878 compareDocumentPosition).
            VmDom::PROP_FIRST_CHILD => JITVariable::TYPE_VALUE,
            VmDom::PROP_LAST_CHILD => JITVariable::TYPE_VALUE,
            VmDom::PROP_NEXT_SIBLING => JITVariable::TYPE_VALUE,
            VmDom::PROP_PREVIOUS_SIBLING => JITVariable::TYPE_VALUE,
            // initTextContentSlot / saveXML / getElementById helpers (#25475).
            VmDom::PROP_TEXT_CONTENT => JITVariable::TYPE_STRING,
            'nodeValue' => JITVariable::TYPE_STRING,
            // ParentNode append/prepend → saveXML child markup (#26765).
            VmDom::PROP_USER_SCRIPT_INNER_XML => JITVariable::TYPE_STRING,
            // DomNodeLiveMutationRuntime::syncElementNavSlots (appendChild path).
            VmDom::PROP_FIRST_ELEMENT_CHILD => JITVariable::TYPE_VALUE,
            VmDom::PROP_LAST_ELEMENT_CHILD => JITVariable::TYPE_VALUE,
            VmDom::PROP_CHILD_ELEMENT_COUNT => JITVariable::TYPE_NATIVE_LONG,
            VmDom::PROP_NEXT_ELEMENT_SIBLING => JITVariable::TYPE_VALUE,
            VmDom::PROP_PREVIOUS_ELEMENT_SIBLING => JITVariable::TYPE_VALUE,
        ] as $prop => $type) {
            if (!$objectType->hasProperty($classId, $prop)) {
                $objectType->defineProperty($classId, $prop, $type);
            }
        }
    }

    private static function loadStringArg(Context $context, JITVariable $arg): Value
    {
        if (JITVariable::TYPE_STRING === $arg->type) {
            return $context->helper->loadValue($arg);
        }
        $valPtr = JitValueBox::valuePtrFromVariable($context, $arg);

        return $context->builder->call(
            $context->lookupFunction('__value__readString'),
            $valPtr
        );
    }

    private static function storeStringProperty(
        Context $context,
        Value $obj,
        string $className,
        string $prop,
        Value $str
    ): void {
        $owned = $context->builder->call(
            $context->lookupFunction('__string__separate'),
            $str
        );
        $propVar = new JITVariable(
            $context,
            JITVariable::TYPE_STRING,
            JITVariable::KIND_VALUE,
            $owned
        );
        $context->type->object->propertyStore(
            $context->type->object->propertySlotFor($obj, $className, $prop),
            $propVar,
            JITVariable::TYPE_STRING
        );
    }

    private static function storeNullProperty(Context $context, Value $obj, string $className, string $prop): void
    {
        $slot = JitValueBox::alloc($context);
        $context->builder->call(
            $context->lookupFunction('__value__writeNull'),
            JitValueBox::pointer($context, $slot)
        );
        $propVar = new JITVariable($context, JITVariable::TYPE_VALUE, JITVariable::KIND_VARIABLE, $slot);
        $context->type->object->propertyStore(
            $context->type->object->propertySlotFor($obj, $className, $prop),
            $propVar,
            JITVariable::TYPE_NULL
        );
    }

    /**
     * #21687: null parentNode + ownerDocument so living-API walks do not read garbage slots.
     */
    private static function storeOwnerAndNullParent(
        Context $context,
        Value $obj,
        JITVariable $documentArg,
        string $className = self::CLASS_ELEMENT
    ): void {
        $objectType = $context->type->object;
        $classId = $objectType->lookup($className);
        self::ensureElementPropertyLayout($objectType, $classId);

        $nullSlot = JitValueBox::alloc($context);
        $context->builder->call(
            $context->lookupFunction('__value__writeNull'),
            JitValueBox::pointer($context, $nullSlot)
        );
        $nullVar = new JITVariable($context, JITVariable::TYPE_VALUE, JITVariable::KIND_VARIABLE, $nullSlot);
        $docObj = self::loadObjectArg($context, $documentArg);
        $docJit = new JITVariable($context, JITVariable::TYPE_OBJECT, JITVariable::KIND_VALUE, $docObj);
        // Element layout only — writing DOMNode slots into a mismatched allocation corrupts memory.
        if (!$objectType->hasProperty($classId, VmDom::PROP_OWNER_DOCUMENT)) {
            $objectType->defineProperty($classId, VmDom::PROP_OWNER_DOCUMENT, JITVariable::TYPE_VALUE);
        }
        $objectType->propertyStore(
            $objectType->propertySlotFor($obj, $className, VmDom::PROP_OWNER_DOCUMENT),
            $docJit,
            JITVariable::TYPE_VALUE
        );
        $objectType->propertyStore(
            $objectType->propertySlotFor($obj, $className, VmDom::PROP_PARENT_NODE),
            $nullVar,
            JITVariable::TYPE_VALUE
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
