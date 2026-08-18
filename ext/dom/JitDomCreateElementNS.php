<?php

declare(strict_types=1);

namespace PHPCompiler\ext\dom;

use PHPCompiler\JIT\Builtin\DomCreateElementNSRuntime;
use PHPCompiler\ext\dom\JitDomDocumentMethodKernel;
use PHPCompiler\JIT\Context;
use PHPCompiler\JIT\JitStringBuiltinArg;
use PHPCompiler\JIT\JitValueBox;
use PHPCompiler\JIT\Variable as JITVariable;
use PHPLLVM\Value;

/**
 * LLVM lowering for DOMDocument::createElementNS() (#14314, #18938, #24923, #32302).
 *
 * php-src: ext/dom/php_dom.stub.php — createElementNS(?string $namespace, …)
 * null namespace ≠ "" (no xmlns vs xmlns="") — preserve on AOT materialization.
 */
final class JitDomCreateElementNS
{
    private const CLASS_ELEMENT = 'DOMElement';

    private const CLASS_LIVING_ELEMENT = 'Dom\\Element';

    private const CLASS_HTML_ELEMENT = 'Dom\\HTMLElement';

    private const HTML_NS = 'http://www.w3.org/1999/xhtml';

    public static function invoke(Context $context, JITVariable ...$args): Value
    {
        if (\count($args) < 3) {
            throw new \LogicException('DOMDocument::createElementNS() expects receiver, namespace, and qualified name');
        }
        $userArgCount = \count($args) - 1;
        if ($userArgCount > 3) {
            throw new \ArgumentCountError(DomClassMethod::atMostUserArgCountMessage(
                'DOMDocument::createElementNS',
                3,
                $userArgCount
            ));
        }

        if (JitDomDocumentMethodKernel::shouldUse($context)) {
            $nsResolved = self::isCompileTimeNullableString($args[1]);
            $nameLit = self::compileTimeStringArg($args[2]);
            if ($nsResolved && null !== $nameLit) {
                $nsLit = self::compileTimeNullableStringArg($args[1]);
                // Invalid QName / NS rules must hit helper (strictErrorChecking) (#24804 / #20594).
                if (!self::needsHelperValidation($nsLit, $nameLit)) {
                    $valueLit = '';
                    if (isset($args[3])) {
                        $vLit = self::compileTimeStringArg($args[3]);
                        if (null === $vLit && JITVariable::TYPE_NULL !== $args[3]->type && !$args[3]->isNullConstant) {
                            return self::invokeViaHelper($context, ...$args);
                        }
                        $valueLit = $vLit ?? '';
                    }
                    $obj = self::materializeElementNSFromLiterals($context, $nsLit, $nameLit, $valueLit);
                    self::storeOwnerAndNullParent($context, $obj, $args[0]);

                    // Box like invokeViaHelper — nested appendChild(createElementNS()) (#29638).
                    return self::boxObjectResult($context, $obj);
                }
            }

            return self::invokeViaHelper($context, ...$args);
        }

        throw new \LogicException('DOMDocument::createElementNS() requires user-script AOT helper in this compiler build');
    }

    /**
     * Living Dom\Document::createElementNS — main-module materialize (#28958 / #21030).
     *
     * @param bool $htmlDocument when true, HTML namespace → Dom\HTMLElement + uppercase names
     */
    public static function invokeLiving(
        Context $context,
        bool $htmlDocument,
        JITVariable ...$args
    ): Value {
        if (\count($args) < 3) {
            throw new \LogicException('Dom\\Document::createElementNS() expects receiver, namespace, and qualified name');
        }
        $userArgCount = \count($args) - 1;
        if ($userArgCount > 3) {
            throw new \ArgumentCountError(DomClassMethod::atMostUserArgCountMessage(
                'Dom\\Document::createElementNS',
                3,
                $userArgCount
            ));
        }

        $nsResolved = self::isCompileTimeNullableString($args[1]);
        $nameLit = self::compileTimeStringArg($args[2]);
        if ($nsResolved && null !== $nameLit) {
            $nsLit = self::compileTimeNullableStringArg($args[1]);
            if (!self::needsHelperValidation($nsLit, $nameLit)) {
                $valueLit = '';
                if (isset($args[3])) {
                    $vLit = self::compileTimeStringArg($args[3]);
                    if (null === $vLit && JITVariable::TYPE_NULL !== $args[3]->type && !$args[3]->isNullConstant) {
                        return self::invokeViaHelper($context, ...$args);
                    }
                    $valueLit = $vLit ?? '';
                }
                $isHtmlNs = $htmlDocument && self::HTML_NS === $nsLit;
                $elementClass = $isHtmlNs ? self::CLASS_HTML_ELEMENT : self::CLASS_LIVING_ELEMENT;
                $qName = $isHtmlNs ? strtoupper($nameLit) : $nameLit;
                $obj = self::materializeElementNSFromLiterals(
                    $context,
                    $nsLit,
                    $qName,
                    $valueLit,
                    $elementClass
                );
                self::storeOwnerAndNullParent($context, $obj, $args[0], $elementClass);

                return self::boxObjectResult($context, $obj);
            }
        }

        return self::invokeViaHelper($context, ...$args);
    }

    /** Namespace arg is compile-time null or string literal. */
    private static function isCompileTimeNullableString(JITVariable $arg): bool
    {
        if (JITVariable::TYPE_NULL === $arg->type || $arg->isNullConstant) {
            return true;
        }

        return null !== self::compileTimeStringArg($arg);
    }

    /** Mirror VmDom::elementNSNameValidationError for literal gating (#24923). */
    private static function needsHelperValidation(?string $namespace, string $qualifiedName): bool
    {
        if ('' === $qualifiedName) {
            return true;
        }
        $pos = strpos($qualifiedName, ':');
        if (false === $pos) {
            if (1 !== preg_match('/^[A-Za-z_][\w.-]*$/', $qualifiedName)) {
                return true;
            }
            $prefix = '';
        } else {
            $prefix = substr($qualifiedName, 0, $pos);
            $local = substr($qualifiedName, $pos + 1);
            if ('' === $prefix || '' === $local || false !== strpos($local, ':')) {
                return true;
            }
            if (1 !== preg_match('/^[A-Za-z_][\w.-]*$/', $prefix)
                || 1 !== preg_match('/^[A-Za-z_][\w.-]*$/', $local)
            ) {
                return true;
            }
        }
        $ns = $namespace ?? '';
        if ('' !== $prefix && '' === $ns) {
            return true;
        }
        if ('xml' === $prefix && 'http://www.w3.org/XML/1998/namespace' !== $ns) {
            return true;
        }
        if ('xmlns' === $prefix && 'http://www.w3.org/2000/xmlns/' !== $ns) {
            return true;
        }

        return false;
    }

    /**
     * Living Dom\Document::createElementNS — helper so HTML ns → Dom\HTMLElement (#28958 / #21030).
     */
    public static function invokeViaHelper(Context $context, JITVariable ...$args): Value
    {
        DomCreateElementNSRuntime::ensureLinked($context);

        $document = self::loadObjectArg($context, $args[0]);
        $namespace = self::loadNullableStringArg($context, $args[1]);
        $qualifiedName = self::loadStringArg($context, $args[2]);
        $value = \count($args) >= 4
            ? self::loadStringArg($context, $args[3])
            : $context->builder->load($context->constantStringFromString(''));

        $element = $context->builder->call(
            $context->lookupFunction(DomCreateElementNSRuntime::ABI_NAME),
            $document,
            $namespace,
            $qualifiedName,
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

    /**
     * AOT-native element with NS property slots (no DomRegistry — same as createElement).
     *
     * @param string|null $namespace null → namespaceURI NULL; "" → empty URI + xmlns=""
     */
    public static function materializeElementNSFromLiterals(
        Context $context,
        ?string $namespace,
        string $qualifiedName,
        string $value = '',
        string $className = self::CLASS_ELEMENT
    ): Value {
        [$prefix, $localName] = self::splitQualifiedName($qualifiedName);
        $objectType = $context->type->object;
        $classId = $objectType->lookup($className);
        self::ensureElementNSPropertyLayout($objectType, $classId);

        $obj = $objectType->allocate($classId);
        $objectType->markObjectConstructed($obj);

        $nameStr = $context->builder->load($context->constantStringFromString($qualifiedName));
        self::storeStringProperty($context, $obj, $className, VmDom::PROP_NODE_NAME, $nameStr);
        self::storeStringProperty($context, $obj, $className, VmDom::PROP_TAG_NAME, $nameStr);
        self::storeStringProperty(
            $context,
            $obj,
            $className,
            VmDom::PROP_LOCAL_NAME,
            $context->builder->load($context->constantStringFromString($localName))
        );
        if ('' !== $prefix) {
            self::storeBoxedStringProperty(
                $context,
                $obj,
                $className,
                VmDom::PROP_PREFIX,
                $context->builder->load($context->constantStringFromString($prefix))
            );
        } else {
            // VM/Zend expose ""; AOT null is acceptable for unprefixed (DomRegistry sync).
            self::storeNullProperty($context, $obj, $className, VmDom::PROP_PREFIX);
        }
        if (null === $namespace) {
            self::storeNullProperty($context, $obj, $className, VmDom::PROP_NAMESPACE_URI);
        } else {
            // VALUE slot (nullable) — must box via __value__writeString, not TYPE_STRING store (#24923).
            self::storeBoxedStringProperty(
                $context,
                $obj,
                $className,
                VmDom::PROP_NAMESPACE_URI,
                $context->builder->load($context->constantStringFromString($namespace))
            );
        }
        self::storeNullProperty($context, $obj, $className, VmDom::PROP_ATTRIBUTES);
        // Always seed textContent/nodeValue/INNER_XML — textContent-only store
        // leaves nodeValue as a null __string__* and the fetch SIGSEGVs (#32302 / #32292).
        JitDomCreateElement::storeTextContentSlots($context, $obj, $value, $className);
        JitDomCreateElement::storeUserScriptXmlnsAttr(
            $context,
            $obj,
            self::xmlnsAttrForSaveXml($namespace, $qualifiedName),
            $className
        );

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

    /**
     * libxml xmlNodeDump nsDef on the dump root (php-src document.c createElementNS).
     *
     * null namespace → no xmlns; "" → xmlns="" / xmlns:prefix="".
     */
    private static function xmlnsAttrForSaveXml(?string $namespace, string $qualifiedName): string
    {
        if (null === $namespace) {
            return '';
        }
        [$prefix] = self::splitQualifiedName($qualifiedName);
        $uri = htmlspecialchars($namespace, ENT_QUOTES | ENT_XML1, 'UTF-8');
        if ('' !== $prefix) {
            return ' xmlns:'.$prefix.'="'.$uri.'"';
        }

        return ' xmlns="'.$uri.'"';
    }

    private static function ensureElementNSPropertyLayout(
        \PHPCompiler\JIT\Builtin\Type\Object_ $objectType,
        int $classId
    ): void {
        foreach ([
            VmDom::PROP_NODE_NAME => JITVariable::TYPE_STRING,
            VmDom::PROP_TAG_NAME => JITVariable::TYPE_STRING,
            VmDom::PROP_LOCAL_NAME => JITVariable::TYPE_STRING,
            VmDom::PROP_PREFIX => JITVariable::TYPE_VALUE,
            VmDom::PROP_NAMESPACE_URI => JITVariable::TYPE_VALUE,
            VmDom::PROP_ATTRIBUTES => JITVariable::TYPE_VALUE,
            VmDom::PROP_PARENT_NODE => JITVariable::TYPE_VALUE,
            VmDom::PROP_OWNER_DOCUMENT => JITVariable::TYPE_VALUE,
            VmDom::PROP_USER_SCRIPT_XMLNS_ATTR => JITVariable::TYPE_STRING,
        ] as $prop => $type) {
            if (!$objectType->hasProperty($classId, $prop)) {
                $objectType->defineProperty($classId, $prop, $type);
            }
        }
    }

    private static function storeOwnerAndNullParent(
        Context $context,
        Value $obj,
        JITVariable $documentArg,
        string $className = self::CLASS_ELEMENT
    ): void {
        $objectType = $context->type->object;
        $classId = $objectType->lookup($className);
        self::ensureElementNSPropertyLayout($objectType, $classId);

        $nullSlot = JitValueBox::alloc($context);
        $context->builder->call(
            $context->lookupFunction('__value__writeNull'),
            JitValueBox::pointer($context, $nullSlot)
        );
        $nullVar = new JITVariable($context, JITVariable::TYPE_VALUE, JITVariable::KIND_VARIABLE, $nullSlot);
        $docObj = self::loadObjectArg($context, $documentArg);
        $docJit = new JITVariable($context, JITVariable::TYPE_OBJECT, JITVariable::KIND_VALUE, $docObj);
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

        throw new \LogicException('DOMDocument::createElementNS() receiver must be an object');
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
        if (JITVariable::TYPE_NULL === $arg->type || $arg->isNullConstant) {
            return $context->builder->load($context->constantStringFromString(''));
        }

        throw new \LogicException('DOMDocument::createElementNS() string argument has invalid type');
    }

    /** ?string ABI — null constant → null __string__* (not empty string) (#24923). */
    private static function loadNullableStringArg(Context $context, JITVariable $arg): Value
    {
        if (JITVariable::TYPE_NULL === $arg->type || $arg->isNullConstant) {
            return $context->getTypeFromString('__string__*')->constNull();
        }
        if (JITVariable::TYPE_STRING === $arg->type) {
            return $context->helper->loadValue($arg);
        }
        if (JITVariable::TYPE_VALUE === $arg->type) {
            return $context->builder->call(
                $context->lookupFunction('__value__readString'),
                JitValueBox::valuePtrFromVariable($context, $arg)
            );
        }

        throw new \LogicException('DOMDocument::createElementNS() namespace argument has invalid type');
    }

    private static function compileTimeStringArg(JITVariable $arg): ?string
    {
        $lit = JitStringBuiltinArg::compileTimeLiteral($arg);
        if (null !== $lit) {
            return $lit;
        }

        return $arg->compileTimeString;
    }

    /** Compile-time null → null; compile-time string → string; else unresolved. */
    private static function compileTimeNullableStringArg(JITVariable $arg): ?string
    {
        if (JITVariable::TYPE_NULL === $arg->type || $arg->isNullConstant) {
            return null;
        }

        return self::compileTimeStringArg($arg);
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

    /** Store string into a TYPE_VALUE (nullable) property slot (#24923). */
    private static function storeBoxedStringProperty(
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
        $slot = JitValueBox::alloc($context);
        $ptr = JitValueBox::pointer($context, $slot);
        $context->builder->call(
            $context->lookupFunction('__value__writeString'),
            $ptr,
            $owned
        );
        $propVar = new JITVariable($context, JITVariable::TYPE_VALUE, JITVariable::KIND_VARIABLE, $slot);
        $context->type->object->propertyStore(
            $context->type->object->propertySlotFor($obj, $className, $prop),
            $propVar,
            JITVariable::TYPE_VALUE
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
