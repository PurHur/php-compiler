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
 * User-script AOT for DOMNode::lookupNamespaceURI() / isDefaultNamespace()
 * (php-src xmlSearchNs).
 *
 * Thin standalone AOT documentElement/firstChild temps lose DOMElement userType
 * and NestedJIT DomRegistry is empty — instance-invoke aborts as
 * object::lookupnamespaceuri(). Fold compile-time loadXML in-scope decls (peer
 * {@see JitDomLookupPrefix}).
 *
 * php-src: ext/dom/node.c PHP_METHOD(DOMNode, lookupNamespaceURI)
 *          ext/dom/node.c PHP_METHOD(DOMNode, isDefaultNamespace)
 *          libxml xmlSearchNs — NULL prefix is the default xmlns (#32504)
 */
final class JitDomLookupNamespaceURI
{
    private const XML_NAMESPACE_URI = 'http://www.w3.org/XML/1998/namespace';
    private const XMLNS_NAMESPACE_URI = 'http://www.w3.org/2000/xmlns/';

    public static function invoke(Context $context, JITVariable ...$args): Value
    {
        BasicBlockHelper::ensureOpenInsertBlock($context, 'dom_lookupnamespaceuri_cont');
        if (!VmClassMethod::requireExactJitUserArgCount(
            $context,
            $args,
            'DOMNode::lookupNamespaceURI',
            1
        )) {
            return VmClassMethod::jitArgcDummyReturn($context);
        }

        $prefix = self::compileTimeNullablePrefix($args[1], 'DOMNode::lookupNamespaceURI');
        $xml = JitDomLoadXMLUserScript::lastCompileTimeXml();
        if (null === $xml || !JitDomLoadXMLUserScript::lastLoadWasPureUserScript()) {
            throw new \LogicException(
                'DOMNode::lookupNamespaceURI() user-script AOT requires compile-time loadXML'
            );
        }

        return self::boxNullableString(
            $context,
            self::uriForPrefixInDocumentElementScope($xml, $prefix)
        );
    }

    public static function invokeIsDefaultNamespace(Context $context, JITVariable ...$args): Value
    {
        BasicBlockHelper::ensureOpenInsertBlock($context, 'dom_isdefaultnamespace_cont');
        if (!VmClassMethod::requireExactJitUserArgCount(
            $context,
            $args,
            'DOMNode::isDefaultNamespace',
            1
        )) {
            return VmClassMethod::jitArgcDummyReturn($context);
        }

        $namespaceArg = $args[1];
        $namespace = JitStringBuiltinArg::compileTimeLiteral($namespaceArg)
            ?? $namespaceArg->compileTimeString;
        if (null === $namespace) {
            throw new \LogicException(
                'DOMNode::isDefaultNamespace() user-script AOT requires a compile-time namespace URI'
            );
        }

        $xml = JitDomLoadXMLUserScript::lastCompileTimeXml();
        if (null === $xml || !JitDomLoadXMLUserScript::lastLoadWasPureUserScript()) {
            throw new \LogicException(
                'DOMNode::isDefaultNamespace() user-script AOT requires compile-time loadXML'
            );
        }

        $defaultNs = self::uriForPrefixInDocumentElementScope($xml, null);

        return self::boxBoolResult($context, null !== $defaultNs && $defaultNs === $namespace);
    }

    /**
     * xmlSearchNs from the document element (inherited by firstChild).
     * NULL / empty prefix is the default xmlns. xml and xmlns are always in scope.
     */
    public static function uriForPrefixInDocumentElementScope(string $xml, ?string $prefix): ?string
    {
        $want = $prefix ?? '';
        if ('xml' === $want) {
            return self::XML_NAMESPACE_URI;
        }
        if ('xmlns' === $want) {
            return self::XMLNS_NAMESPACE_URI;
        }
        $elements = DomParseSimpleXmlJitHelper::walkElementsInScopeNamespaces($xml);
        if ([] === $elements) {
            return null;
        }

        return $elements[0]['inScope'][$want] ?? null;
    }

    private static function compileTimeNullablePrefix(JITVariable $arg, string $methodName): ?string
    {
        if (JITVariable::TYPE_NULL === $arg->type || $arg->isNullConstant) {
            return null;
        }
        $lit = JitStringBuiltinArg::compileTimeLiteral($arg) ?? $arg->compileTimeString;
        if (null === $lit) {
            throw new \LogicException(
                $methodName.'() user-script AOT requires a compile-time prefix'
            );
        }

        return $lit;
    }

    private static function boxNullableString(Context $context, ?string $lit): Value
    {
        $slot = JitValueBox::alloc($context);
        $ptr = JitValueBox::pointer($context, $slot);
        if (null === $lit) {
            $context->builder->call(
                $context->lookupFunction('__value__writeNull'),
                $ptr
            );

            return JitValueBox::normalizeValuePtr($context, $slot);
        }
        $str = $context->builder->load($context->constantStringFromString($lit));
        $owned = $context->builder->call(
            $context->lookupFunction('__string__separate'),
            $str
        );
        $context->builder->call(
            $context->lookupFunction('__value__writeString'),
            $ptr,
            $owned
        );

        return JitValueBox::normalizeValuePtr($context, $slot);
    }

    private static function boxBoolResult(Context $context, bool $value): Value
    {
        $slot = JitValueBox::alloc($context);
        JitValueBox::writeBool(
            $context,
            $slot,
            $context->getTypeFromString('int1')->constInt($value ? 1 : 0, false)
        );

        return JitValueBox::normalizeValuePtr($context, $slot);
    }
}
