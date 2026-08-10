<?php

declare(strict_types=1);

namespace PHPCompiler\ext\dom;

use PHPCompiler\JIT\BasicBlockHelper;
use PHPCompiler\ext\dom\JitDomDocumentMethodKernel;
use PHPCompiler\JIT\Builtin\DomImportNodeRuntime;
use PHPCompiler\JIT\Context;
use PHPCompiler\JIT\JitStringBuiltinArg;
use PHPCompiler\JIT\JitValueBox;
use PHPCompiler\JIT\Variable as JITVariable;
use PHPLLVM\Builder;
use PHPLLVM\Value;

/**
 * LLVM lowering for DOMElement AttributeNodeNS + DOMDocument::createAttributeNS (#19265, #19268).
 *
 * User-script AOT materializes DOMAttr in LLVM (DomRegistry helpers segfault standalone).
 */
final class JitDomAttributeNodeNS
{
    private const CLASS_ATTR = 'DOMAttr';

    private const PROP_NODE_NAME = 'nodeName';

    private const PROP_NAME = 'name';

    private const PROP_VALUE = 'value';

    private const PROP_NODE_VALUE = 'nodeValue';

    private const PROP_OWNER_ELEMENT = 'ownerElement';

    private const PROP_NAMESPACE_URI = 'namespaceURI';

    private const PROP_LOCAL_NAME = 'localName';

    private const PROP_PREFIX = 'prefix';

    private static int $boxSeq = 0;

    public static function invokeGet(Context $context, JITVariable ...$args): Value
    {
        if (\count($args) < 3) {
            throw new \LogicException('DOMElement::getAttributeNodeNS() expects receiver, namespace, and localName');
        }

        BasicBlockHelper::ensureOpenInsertBlock($context, 'dom_getattrnodens_cont');

        if (JitDomDocumentMethodKernel::shouldUse($context)) {
            return self::invokeGetUserScript($context, ...$args);
        }

        DomImportNodeRuntime::ensureGetAttributeNodeNSLinked($context);

        $element = self::loadObjectArg($context, $args[0], 'DOMElement::getAttributeNodeNS() receiver');
        $namespace = self::loadStringArg($context, $args[1]);
        $localName = self::loadStringArg($context, $args[2]);
        $attr = $context->builder->call(
            $context->lookupFunction(DomImportNodeRuntime::ABI_GET_ATTRIBUTE_NODE_NS),
            $element,
            $namespace,
            $localName
        );

        return self::boxNullableObjectResult($context, $attr);
    }

    public static function invokeSet(Context $context, JITVariable ...$args): Value
    {
        if (\count($args) < 2) {
            throw new \LogicException('DOMElement::setAttributeNodeNS() expects receiver and attr');
        }

        BasicBlockHelper::ensureOpenInsertBlock($context, 'dom_setattrnodens_cont');

        if (JitDomDocumentMethodKernel::shouldUse($context)) {
            return self::invokeSetUserScript($context, ...$args);
        }

        DomImportNodeRuntime::ensureSetAttributeNodeNSLinked($context);

        $element = self::loadObjectArg($context, $args[0], 'DOMElement::setAttributeNodeNS() receiver');
        $attr = self::loadObjectArg($context, $args[1], 'DOMElement::setAttributeNodeNS() attr');
        $replaced = $context->builder->call(
            $context->lookupFunction(DomImportNodeRuntime::ABI_SET_ATTRIBUTE_NODE_NS),
            $element,
            $attr
        );

        return self::boxNullableObjectResult($context, $replaced);
    }

    public static function invokeCreate(Context $context, JITVariable ...$args): Value
    {
        if (\count($args) < 3) {
            throw new \LogicException('DOMDocument::createAttributeNS() expects receiver, namespace, and qualifiedName');
        }

        BasicBlockHelper::ensureOpenInsertBlock($context, 'dom_createattrns_cont');

        if (JitDomDocumentMethodKernel::shouldUse($context)) {
            return self::invokeCreateUserScript($context, ...$args);
        }

        DomImportNodeRuntime::ensureCreateAttributeNSLinked($context);

        $document = self::loadObjectArg($context, $args[0], 'DOMDocument::createAttributeNS() receiver');
        $namespace = self::loadStringArg($context, $args[1]);
        $qualifiedName = self::loadStringArg($context, $args[2]);
        $attr = $context->builder->call(
            $context->lookupFunction(DomImportNodeRuntime::ABI_CREATE_ATTRIBUTE_NS),
            $document,
            $namespace,
            $qualifiedName
        );

        return self::boxObjectResult($context, $attr);
    }

    /**
     * DOMDocument::createAttribute() — non-NS Attr (php-src document.c; #20676).
     * Reuses the empty-namespace Attr cache key shared with setAttribute/getAttributeNode.
     */
    public static function invokeCreateAttribute(Context $context, JITVariable ...$args): Value
    {
        if (\count($args) < 2) {
            throw new \LogicException('DOMDocument::createAttribute() expects receiver and name');
        }

        // Compile-time null under strict_types (#29985, peer #29959).
        if ($context->callerStrictTypes && JITVariable::TYPE_NULL === $args[1]->type) {
            \PHPCompiler\JIT\JitNativeString::ensureInsertBlock($context);
            \PHPCompiler\JIT\ExceptionBridge::emitTypeErrorAndAbort(
                $context,
                'DOMDocument::createAttribute(): Argument #1 ($localName) must be of type string, null given'
            );

            return self::boxNullResult($context);
        }

        BasicBlockHelper::ensureOpenInsertBlock($context, 'dom_createattr_cont');

        if (JitDomDocumentMethodKernel::shouldUse($context)) {
            return self::invokeCreateAttributeUserScript($context, ...$args);
        }

        DomImportNodeRuntime::ensureCreateAttributeLinked($context);

        $document = self::loadObjectArg($context, $args[0], 'DOMDocument::createAttribute() receiver');
        $name = self::loadStringArg($context, $args[1]);
        $attr = $context->builder->call(
            $context->lookupFunction(DomImportNodeRuntime::ABI_CREATE_ATTRIBUTE),
            $document,
            $name
        );

        return self::boxObjectResult($context, $attr);
    }

    /**
     * DOMElement::setAttributeNode() — non-NS (php-src element.c; #20676).
     * User-script path shares Attr cache with setAttributeNodeNS via rememberCreate('', name).
     */
    public static function invokeSetAttributeNode(Context $context, JITVariable ...$args): Value
    {
        if (\count($args) < 2) {
            throw new \LogicException('DOMElement::setAttributeNode() expects receiver and attr');
        }

        BasicBlockHelper::ensureOpenInsertBlock($context, 'dom_setattrnode_cont');

        if (JitDomDocumentMethodKernel::shouldUse($context)) {
            return self::invokeSetUserScript($context, ...$args);
        }

        DomImportNodeRuntime::ensureSetAttributeNodeLinked($context);

        $element = self::loadObjectArg($context, $args[0], 'DOMElement::setAttributeNode() receiver');
        $attr = self::loadObjectArg($context, $args[1], 'DOMElement::setAttributeNode() attr');
        $replaced = $context->builder->call(
            $context->lookupFunction(DomImportNodeRuntime::ABI_SET_ATTRIBUTE_NODE),
            $element,
            $attr
        );

        return self::boxNullableObjectResult($context, $replaced);
    }

    private static function invokeCreateAttributeUserScript(Context $context, JITVariable ...$args): Value
    {
        $nameLit = self::compileTimeStringArg($args[1]);
        // Invalid literal → VmDom helper (throws); valid literal keeps materialize (#24804).
        if (null !== $nameLit && !self::isValidXmlNameLit($nameLit)) {
            DomImportNodeRuntime::ensureCreateAttributeLinked($context);
            $document = self::loadObjectArg($context, $args[0], 'DOMDocument::createAttribute() receiver');
            $name = self::loadStringArg($context, $args[1]);
            $attr = $context->builder->call(
                $context->lookupFunction(DomImportNodeRuntime::ABI_CREATE_ATTRIBUTE),
                $document,
                $name
            );

            return self::boxObjectResult($context, $attr);
        }
        if (null !== $nameLit) {
            DomUserScriptAttributeCacheLlvm::rememberCreate('', $nameLit);
            $living = null !== JitDomLoadXMLUserScript::lastDocumentClass()
                && str_starts_with(JitDomLoadXMLUserScript::lastDocumentClass(), 'Dom\\');
            // Classic DOMAttr orphans are never IDs (#29884); living path already did this.
            JitDomAttrRename::rememberOrphan();

            return self::boxObjectResult(
                $context,
                self::materializeAttrFromLiterals(
                    $context,
                    '',
                    $nameLit,
                    '',
                    $living ? self::CLASS_LIVING_ATTR : self::CLASS_ATTR,
                    $living
                )
            );
        }

        DomImportNodeRuntime::ensureCreateAttributeLinked($context);
        $document = self::loadObjectArg($context, $args[0], 'DOMDocument::createAttribute() receiver');
        $name = self::loadStringArg($context, $args[1]);
        $attr = $context->builder->call(
            $context->lookupFunction(DomImportNodeRuntime::ABI_CREATE_ATTRIBUTE),
            $document,
            $name
        );

        return self::boxObjectResult($context, $attr);
    }

    /** Mirror VmDom::isValidXmlName for AOT literal gating (#24804). */
    private static function isValidXmlNameLit(string $name): bool
    {
        return '' !== $name && 1 === preg_match('/^[A-Za-z_:][\w.:-]*$/', $name);
    }

    private static function invokeCreateUserScript(Context $context, JITVariable ...$args): Value
    {
        $nsLit = self::compileTimeStringArg($args[1]);
        $qLit = self::compileTimeStringArg($args[2]);
        if (null !== $nsLit && null !== $qLit) {
            DomUserScriptAttributeCacheLlvm::rememberCreate($nsLit, $qLit);

            return self::boxObjectResult(
                $context,
                self::materializeAttrFromLiterals($context, $nsLit, $qLit, '')
            );
        }

        $namespace = self::loadStringArg($context, $args[1]);
        $qualifiedName = self::loadStringArg($context, $args[2]);

        return self::boxObjectResult(
            $context,
            self::materializeAttrFromRuntime($context, $namespace, $qualifiedName, null)
        );
    }

    private static function invokeGetUserScript(Context $context, JITVariable ...$args): Value
    {
        $nsLit = self::compileTimeStringArg($args[1]);
        $localLit = self::compileTimeStringArg($args[2]);
        $xml = JitDomLoadXMLUserScript::lastCompileTimeXml();
        if (null !== $nsLit && null !== $localLit && null !== $xml) {
            $parsed = DomParseSimpleXmlJitHelper::findAttributeNSArgv($xml, $nsLit, $localLit);
            if (null !== $parsed) {
                $attr = self::materializeAttrFromLiterals(
                    $context,
                    $parsed['namespace'],
                    $parsed['qname'],
                    $parsed['value']
                );
                DomUserScriptAttributeCacheLlvm::storeLiteral($context, $nsLit, $localLit, $attr);

                return self::boxObjectResult($context, $attr);
            }

            // Not found in compile-time XML — php-src returns null.
            return self::boxNullResult($context);
        }

        if (null !== $nsLit && null !== $localLit) {
            return self::boxNullableObjectResult(
                $context,
                DomUserScriptAttributeCacheLlvm::lookupLiteral($context, $nsLit, $localLit)
            );
        }

        return self::boxNullResult($context);
    }

    private static function invokeSetUserScript(Context $context, JITVariable ...$args): Value
    {
        $attr = self::loadObjectArg($context, $args[1], 'DOMElement::setAttributeNodeNS() attr');
        $ns = DomUserScriptAttributeCacheLlvm::lastCreateNamespace();
        $local = DomUserScriptAttributeCacheLlvm::lastCreateLocalName();
        if (null === $ns || null === $local) {
            return self::boxNullResult($context);
        }
        $prev = DomUserScriptAttributeCacheLlvm::storeLiteral($context, $ns, $local, $attr);
        $objPtr = $context->getTypeFromString('__object__*');
        $isNull = $context->builder->icmp(
            Builder::INT_EQ,
            $prev,
            $objPtr->constNull()
        );
        // Only box the previous attr when non-null (avoid writeObject(null)).
        $tag = (string) (self::$boxSeq++);
        $nullBlock = BasicBlockHelper::append($context, 'dom_setattr_prev_null_'.$tag);
        $objBlock = BasicBlockHelper::append($context, 'dom_setattr_prev_obj_'.$tag);
        $doneBlock = BasicBlockHelper::append($context, 'dom_setattr_prev_done_'.$tag);
        $resultSlot = BasicBlockHelper::entryAlloca($context, $context->getTypeFromString('__value__*'));
        $context->builder->branchIf($isNull, $nullBlock, $objBlock);

        $context->builder->positionAtEnd($nullBlock);
        $context->builder->store(self::boxNullResult($context), $resultSlot);
        $context->builder->branch($doneBlock);

        $context->builder->positionAtEnd($objBlock);
        $context->builder->store(self::boxObjectResult($context, $prev), $resultSlot);
        $context->builder->branch($doneBlock);

        $context->builder->positionAtEnd($doneBlock);

        return $context->builder->load($resultSlot);
    }

    public static function materializeAttrFromLiterals(
        Context $context,
        string $namespace,
        string $qualifiedName,
        string $value,
        string $className = self::CLASS_ATTR,
        bool $livingNameIsQName = false
    ): Value {
        [$prefix, $localName] = self::splitQualifiedName($qualifiedName);
        $objectType = $context->type->object;
        $classId = $objectType->lookup($className);
        self::ensureAttrPropertyLayout($objectType, $classId);

        $obj = $objectType->allocate($classId);
        $objectType->markObjectConstructed($obj);

        self::storeStringProperty($context, $obj, self::PROP_NODE_NAME, $qualifiedName, $className);
        // php-src attr.c: legacy DOMAttr.name is local (#19754); living Attr.name is QName (#26024).
        $nameProp = $livingNameIsQName ? $qualifiedName : $localName;
        self::storeStringProperty($context, $obj, self::PROP_NAME, $nameProp, $className);
        self::storeStringProperty($context, $obj, self::PROP_VALUE, $value, $className);
        self::storeStringProperty($context, $obj, self::PROP_NODE_VALUE, $value, $className);
        self::storeStringProperty($context, $obj, self::PROP_NAMESPACE_URI, $namespace, $className);
        self::storeStringProperty($context, $obj, self::PROP_LOCAL_NAME, $localName, $className);
        self::storeStringProperty($context, $obj, self::PROP_PREFIX, $prefix, $className);
        self::storeNullProperty($context, $obj, self::PROP_OWNER_ELEMENT, $className);

        return $obj;
    }

    public const CLASS_LIVING_ATTR = 'Dom\\Attr';

    /**
     * Class layout for Attrs in the user-script cache (#27108 / #29642).
     * Living Dom\* documents materialize Dom\Attr; classic DOMDocument uses DOMAttr.
     */
    public static function attrClassForUserScriptCache(): string
    {
        $docClass = JitDomLoadXMLUserScript::lastDocumentClass();
        if (null !== $docClass && str_starts_with((string) $docClass, 'Dom\\')) {
            return self::CLASS_LIVING_ATTR;
        }

        return self::CLASS_ATTR;
    }

    /** Seed Dom\Attr::rename for method_exists under thin AOT (#27108). */
    public static function ensureLivingAttrMethods(Context $context): void
    {
        $objectType = $context->type->object;
        $classId = $objectType->lookup(self::CLASS_LIVING_ATTR);
        self::ensureAttrPropertyLayout($objectType, $classId);
        $pub = \PHPCfg\Func::FLAG_PUBLIC;
        if (!$objectType->hasMethod($classId, 'rename')) {
            $objectType->defineMethodVisibility($classId, 'rename', $pub);
        }
        $elemId = $objectType->lookup('Dom\\Element');
        foreach ([
            'getattributenode', 'getattribute', 'hasattribute', 'getattributens',
            'hasattributens', 'getattributenodens', 'setattribute',
        ] as $method) {
            if (!$objectType->hasMethod($elemId, $method)) {
                $objectType->defineMethodVisibility($elemId, $method, $pub);
            }
        }
        $docId = $objectType->lookup('Dom\\XMLDocument');
        if (!$objectType->hasMethod($docId, 'createattribute')) {
            $objectType->defineMethodVisibility($docId, 'createattribute', $pub);
        }
    }

    private static function materializeAttrFromRuntime(
        Context $context,
        Value $namespace,
        Value $qualifiedName,
        ?Value $value
    ): Value {
        $objectType = $context->type->object;
        $classId = $objectType->lookup(self::CLASS_ATTR);
        self::ensureAttrPropertyLayout($objectType, $classId);

        $obj = $objectType->allocate($classId);
        $objectType->markObjectConstructed($obj);

        self::storeStringPropertyValue($context, $obj, self::PROP_NODE_NAME, $qualifiedName);
        self::storeStringPropertyValue($context, $obj, self::PROP_NAME, $qualifiedName);
        $valueStr = $value ?? $context->builder->load($context->constantStringFromString(''));
        self::storeStringPropertyValue($context, $obj, self::PROP_VALUE, $valueStr);
        self::storeStringPropertyValue($context, $obj, self::PROP_NODE_VALUE, $valueStr);
        self::storeStringPropertyValue($context, $obj, self::PROP_NAMESPACE_URI, $namespace);
        self::storeStringPropertyValue($context, $obj, self::PROP_LOCAL_NAME, $qualifiedName);
        self::storeStringProperty($context, $obj, self::PROP_PREFIX, '');
        self::storeNullProperty($context, $obj, self::PROP_OWNER_ELEMENT);

        return $obj;
    }

    /** @return array{0: string, 1: string} */
    private static function splitQualifiedName(string $qualifiedName): array
    {
        $pos = strpos($qualifiedName, ':');
        if (false === $pos) {
            return ['', $qualifiedName];
        }

        return [substr($qualifiedName, 0, $pos), substr($qualifiedName, $pos + 1)];
    }

    private static function ensureAttrPropertyLayout(
        \PHPCompiler\JIT\Builtin\Type\Object_ $objectType,
        int $classId
    ): void {
        foreach ([
            self::PROP_NODE_NAME,
            self::PROP_NAME,
            self::PROP_VALUE,
            self::PROP_NODE_VALUE,
            self::PROP_NAMESPACE_URI,
            self::PROP_LOCAL_NAME,
            self::PROP_PREFIX,
        ] as $prop) {
            if (!$objectType->hasProperty($classId, $prop)) {
                $objectType->defineProperty($classId, $prop, JITVariable::TYPE_STRING);
            }
        }
        if (!$objectType->hasProperty($classId, self::PROP_OWNER_ELEMENT)) {
            $objectType->defineProperty($classId, self::PROP_OWNER_ELEMENT, JITVariable::TYPE_VALUE);
        }
    }

    private static function storeStringProperty(
        Context $context,
        Value $obj,
        string $prop,
        string $lit,
        string $className = self::CLASS_ATTR
    ): void {
        $str = $context->builder->load($context->constantStringFromString($lit));
        self::storeStringPropertyValue($context, $obj, $prop, $str, $className);
    }

    private static function storeStringPropertyValue(
        Context $context,
        Value $obj,
        string $prop,
        Value $str,
        string $className = self::CLASS_ATTR
    ): void {
        $owned = $context->builder->call(
            $context->lookupFunction('__string__separate'),
            $str
        );
        $propVar = new JITVariable($context, JITVariable::TYPE_STRING, JITVariable::KIND_VALUE, $owned);
        $context->type->object->propertyStore(
            $context->type->object->propertySlotFor($obj, $className, $prop),
            $propVar,
            JITVariable::TYPE_STRING
        );
    }

    private static function storeNullProperty(
        Context $context,
        Value $obj,
        string $prop,
        string $className = self::CLASS_ATTR
    ): void {
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

    private static function loadObjectArg(Context $context, JITVariable $arg, string $label): Value
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

        throw new \LogicException($label.' must be an object');
    }

    private static function loadStringArg(Context $context, JITVariable $arg): Value
    {
        if (JITVariable::TYPE_STRING === $arg->type) {
            return $context->helper->loadValue($arg);
        }
        if (JITVariable::TYPE_VALUE === $arg->type) {
            return $context->builder->call(
                $context->lookupFunction('__value__readString'),
                JitValueBox::valuePtrFromVariable($context, $arg)
            );
        }
        if (JITVariable::TYPE_NULL === $arg->type) {
            return $context->builder->load($context->constantStringFromString(''));
        }

        throw new \LogicException('AttributeNodeNS string argument must be string or null');
    }

    private static function compileTimeStringArg(JITVariable $arg): ?string
    {
        $lit = JitStringBuiltinArg::compileTimeLiteral($arg);
        if (null !== $lit) {
            return $lit;
        }

        return $arg->compileTimeString;
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

    private static function boxNullResult(Context $context): Value
    {
        $slot = JitValueBox::alloc($context);
        $ptr = JitValueBox::pointer($context, $slot);
        $context->builder->call($context->lookupFunction('__value__writeNull'), $ptr);

        return JitValueBox::normalizeValuePtr($context, $ptr);
    }

    private static function boxNullableObjectResult(Context $context, Value $object): Value
    {
        $tag = (string) (self::$boxSeq++);
        $objPtr = $context->getTypeFromString('__object__*');
        $isNull = $context->builder->icmp(
            Builder::INT_EQ,
            $object,
            $objPtr->constNull()
        );
        $nullBlock = BasicBlockHelper::append($context, 'dom_attr_box_null_'.$tag);
        $objBlock = BasicBlockHelper::append($context, 'dom_attr_box_obj_'.$tag);
        $doneBlock = BasicBlockHelper::append($context, 'dom_attr_box_done_'.$tag);
        $resultSlot = BasicBlockHelper::entryAlloca($context, $context->getTypeFromString('__value__*'));
        $context->builder->branchIf($isNull, $nullBlock, $objBlock);

        // boxNull/ObjectResult already return normalized __value__* — do not wrap again (#19281).
        $context->builder->positionAtEnd($nullBlock);
        $context->builder->store(self::boxNullResult($context), $resultSlot);
        $context->builder->branch($doneBlock);

        $context->builder->positionAtEnd($objBlock);
        $context->builder->store(self::boxObjectResult($context, $object), $resultSlot);
        $context->builder->branch($doneBlock);

        $context->builder->positionAtEnd($doneBlock);

        return $context->builder->load($resultSlot);
    }

    /**
     * True when compile-time name is (or will be) keyed in the live Attr cache (#19281).
     */
    public static function userScriptAttrCacheHasName(Context $context, ?JITVariable $nameArg): bool
    {
        if (null === $nameArg) {
            return false;
        }
        $nameLit = self::compileTimeStringArg($nameArg);
        if (null === $nameLit) {
            return false;
        }
        // Force slot creation check against keys already stored this module, or setAttribute
        // that registered via rememberCreate / storeLiteral earlier in this compile.
        return DomUserScriptAttributeCacheLlvm::hasLiteralKey('', $nameLit);
    }

    /**
     * DOMElement::setAttribute() — user-script AOT live Attr cache (#19281).
     * HTML id rebind updates getElementById map (#19870).
     * Returns the Attr (xmlns → true) like php-src DOM_RET_OBJ (#24538).
     * Rewrite returns the same cached Attr instance (php-src xmlSetProp).
     */
    public static function invokeSetAttribute(Context $context, JITVariable ...$args): Value
    {
        if (\count($args) < 3) {
            throw new \LogicException('DOMElement::setAttribute() expects receiver, name, and value');
        }
        BasicBlockHelper::ensureOpenInsertBlock($context, 'dom_setattr_cont');
        $nameLit = self::compileTimeStringArg($args[1]);
        $valueLit = self::compileTimeStringArg($args[2]);
        if (null !== $nameLit && null !== $valueLit) {
            // php-src xmlns → RETURN_TRUE (nsDef), not Attr (#24538).
            if ('xmlns' === $nameLit) {
                return self::boxBoolResult($context, true);
            }
            $attr = self::setAttributeLiteralReuseOrCreate($context, $nameLit, $valueLit);
            if ('id' === $nameLit) {
                DomUserScriptElementCacheLlvm::rebindId($context, $valueLit);
                JitDomSetIdAttribute::rememberSetAttributeIdValue($valueLit);
                // Keep setIdAttribute cache in sync when setAttribute runs after a prior
                // setIdAttribute in the same script (multi-document #29257).
                $parsed = JitDomLoadHTMLUserScript::lastCompileTimeParsed();
                if (null !== $parsed) {
                    $parsed['id'] = $valueLit;
                    JitDomLoadHTMLUserScript::rememberCompileTimeParsed($parsed);
                }
            }

            return self::boxObjectResult($context, $attr);
        }
        $name = self::loadStringArg($context, $args[1]);
        $value = self::loadStringArg($context, $args[2]);
        $attr = self::materializeAttrFromRuntime($context, $context->builder->load($context->constantStringFromString('')), $name, $value);
        // Runtime name: cannot key the compile-time cache; still materialize Attr for property writes.
        return self::boxObjectResult($context, $attr);
    }

    /**
     * Reuse cached Attr on rewrite so setAttribute() returns the same instance (#24538).
     */
    private static function setAttributeLiteralReuseOrCreate(
        Context $context,
        string $nameLit,
        string $valueLit
    ): Value {
        $tag = (string) (self::$boxSeq++);
        $objPtr = $context->getTypeFromString('__object__*');
        $existing = DomUserScriptAttributeCacheLlvm::lookupLiteral($context, '', $nameLit);
        $isNull = $context->builder->icmp(Builder::INT_EQ, $existing, $objPtr->constNull());
        $createBlock = BasicBlockHelper::append($context, 'dom_setattr_create_'.$tag);
        $updateBlock = BasicBlockHelper::append($context, 'dom_setattr_update_'.$tag);
        $doneBlock = BasicBlockHelper::append($context, 'dom_setattr_done_'.$tag);
        $resultSlot = BasicBlockHelper::entryAlloca($context, $objPtr);
        $context->builder->branchIf($isNull, $createBlock, $updateBlock);

        $context->builder->positionAtEnd($createBlock);
        $created = self::materializeAttrFromLiterals($context, '', $nameLit, $valueLit);
        DomUserScriptAttributeCacheLlvm::rememberCreate('', $nameLit);
        // Record initial value for parse-style hasPresentLiteral readers; live getAttribute
        // still prefers Attr::$value so later writes stay visible (#29642).
        DomUserScriptAttributeCacheLlvm::storeLiteral($context, '', $nameLit, $created, $valueLit);
        $context->builder->store($created, $resultSlot);
        $context->builder->branch($doneBlock);

        $context->builder->positionAtEnd($updateBlock);
        self::storeStringProperty($context, $existing, self::PROP_VALUE, $valueLit);
        self::storeStringProperty($context, $existing, self::PROP_NODE_VALUE, $valueLit);
        $context->builder->store($existing, $resultSlot);
        $context->builder->branch($doneBlock);

        $context->builder->positionAtEnd($doneBlock);

        return $context->builder->load($resultSlot);
    }

    private static function boxBoolResult(Context $context, bool $value): Value
    {
        $slot = JitValueBox::alloc($context);
        $ptr = JitValueBox::pointer($context, $slot);
        $i1 = $context->getTypeFromString('int1');
        JitValueBox::writeBool($context, $slot, $i1->constInt($value ? 1 : 0, false));

        return JitValueBox::normalizeValuePtr($context, $ptr);
    }

    /**
     * DOMElement::removeAttribute() — user-script AOT (#19870).
     */
    public static function invokeRemoveAttribute(Context $context, JITVariable ...$args): Value
    {
        if (\count($args) < 2) {
            throw new \LogicException('DOMElement::removeAttribute() expects receiver and name');
        }
        BasicBlockHelper::ensureOpenInsertBlock($context, 'dom_removeattr_cont');
        $nameLit = self::compileTimeStringArg($args[1]);
        if (null !== $nameLit && 'id' === $nameLit) {
            DomUserScriptElementCacheLlvm::clearId($context);
            $parsed = JitDomLoadHTMLUserScript::lastCompileTimeParsed();
            if (null !== $parsed) {
                $parsed['id'] = '';
                JitDomLoadHTMLUserScript::rememberCompileTimeParsed($parsed);
            }
        }
        $slot = JitValueBox::alloc($context);
        $ptr = JitValueBox::pointer($context, $slot);
        $i1 = $context->getTypeFromString('int1');
        JitValueBox::writeBool($context, $slot, $i1->constInt(1, false));

        return JitValueBox::normalizeValuePtr($context, $ptr);
    }

    /**
     * DOMElement::getAttributeNode() — user-script AOT live Attr cache (#19281, #27108).
     */
    public static function invokeGetAttributeNode(Context $context, JITVariable ...$args): Value
    {
        if (\count($args) < 2) {
            throw new \LogicException('DOMElement::getAttributeNode() expects receiver and name');
        }
        BasicBlockHelper::ensureOpenInsertBlock($context, 'dom_getattrnode_cont');
        $nameLit = self::compileTimeStringArg($args[1]);
        if (null !== $nameLit) {
            \PHPCompiler\ext\dom\JitDomAttrRename::rememberFetchedKey('', $nameLit);

            return self::boxNullableObjectResult(
                $context,
                DomUserScriptAttributeCacheLlvm::lookupLiteral($context, '', $nameLit)
            );
        }

        return self::boxNullResult($context);
    }

    /**
     * DOMElement::getAttribute() — read live Attr::$value from user-script cache (#19281).
     */
    public static function invokeGetAttributeLive(Context $context, JITVariable ...$args): Value
    {
        if (\count($args) < 2) {
            throw new \LogicException('DOMElement::getAttribute() expects receiver and name');
        }
        BasicBlockHelper::ensureOpenInsertBlock($context, 'dom_getattr_live_cont');
        $nameLit = self::compileTimeStringArg($args[1]);
        $empty = $context->builder->load($context->constantStringFromString(''));
        if (null === $nameLit) {
            return self::boxStringResult($context, $empty);
        }
        $attr = DomUserScriptAttributeCacheLlvm::lookupLiteral($context, '', $nameLit);
        $objPtr = $context->getTypeFromString('__object__*');
        $isNull = $context->builder->icmp(Builder::INT_EQ, $attr, $objPtr->constNull());
        $tag = (string) (self::$boxSeq++);
        $nullBlock = BasicBlockHelper::append($context, 'dom_getattr_live_null_'.$tag);
        $objBlock = BasicBlockHelper::append($context, 'dom_getattr_live_obj_'.$tag);
        $doneBlock = BasicBlockHelper::append($context, 'dom_getattr_live_done_'.$tag);
        $resultSlot = BasicBlockHelper::entryAlloca($context, $context->getTypeFromString('__value__*'));
        $context->builder->branchIf($isNull, $nullBlock, $objBlock);

        $context->builder->positionAtEnd($nullBlock);
        $context->builder->store(self::boxStringResult($context, $empty), $resultSlot);
        $context->builder->branch($doneBlock);

        $context->builder->positionAtEnd($objBlock);
        // Attr cache may hold Dom\Attr (living createFromString) or DOMAttr (#27108).
        // Classic setAttribute materializes DOMAttr; reading Dom\Attr slots missed live writes (#29642).
        $attrClass = self::attrClassForUserScriptCache();
        $valueVar = $context->type->object->propertyFetch($attr, $attrClass, self::PROP_VALUE);
        $str = $context->helper->loadValue($valueVar);
        $context->builder->store(self::boxStringResult($context, $str), $resultSlot);
        $context->builder->branch($doneBlock);

        $context->builder->positionAtEnd($doneBlock);

        return $context->builder->load($resultSlot);
    }

    private static function boxStringResult(Context $context, Value $str): Value
    {
        $slot = JitValueBox::alloc($context);
        $ptr = JitValueBox::pointer($context, $slot);
        $context->builder->call(
            $context->lookupFunction('__value__writeString'),
            $ptr,
            $str
        );

        return JitValueBox::normalizeValuePtr($context, $ptr);
    }
}
