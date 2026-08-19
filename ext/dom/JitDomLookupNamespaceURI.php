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
 * User-script AOT for DOMNode::lookupNamespaceURI() (php-src xmlSearchNs).
 *
 * Thin standalone AOT documentElement/firstChild temps lose DOMElement userType
 * and NestedJIT DomRegistry is empty — instance-invoke aborts as
 * object::lookupnamespaceuri(). Fold compile-time loadXML xmlns decls (peer
 * {@see JitDomLookupPrefix}).
 *
 * php-src: ext/dom/node.c PHP_METHOD(DOMNode, lookupNamespaceURI)
 *          libxml xmlSearchNs — null prefix is the default xmlns (#32502)
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

        $prefixArg = $args[1];
        $prefix = self::compileTimePrefix($prefixArg);
        if (false === $prefix) {
            throw new \LogicException(
                'DOMNode::lookupNamespaceURI() user-script AOT requires a compile-time prefix or null'
            );
        }

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

    /**
     * xmlSearchNs from the document element (inherited by firstChild).
     * Null prefix is the default xmlns. Reserved xml/xmlns prefixes always resolve.
     *
     * @param string|null $prefix null means default xmlns (php-src Z_PARAM_STR_OR_NULL)
     */
    public static function uriForPrefixInDocumentElementScope(string $xml, ?string $prefix): ?string
    {
        $wantPrefix = $prefix ?? '';
        if ('xml' === $wantPrefix) {
            return self::XML_NAMESPACE_URI;
        }
        if ('xmlns' === $wantPrefix) {
            return self::XMLNS_NAMESPACE_URI;
        }
        $elements = DomParseSimpleXmlJitHelper::walkElementsInScopeNamespaces($xml);
        if ([] === $elements) {
            return null;
        }
        $inScope = $elements[0]['inScope'];
        if (!\array_key_exists($wantPrefix, $inScope)) {
            return null;
        }

        return $inScope[$wantPrefix];
    }

    /**
     * @return string|null|false  string/null = compile-time prefix; false = not foldable
     */
    private static function compileTimePrefix(JITVariable $prefixArg)
    {
        if (JITVariable::TYPE_NULL === $prefixArg->type || !empty($prefixArg->isNullConstant)) {
            return null;
        }
        $lit = JitStringBuiltinArg::compileTimeLiteral($prefixArg)
            ?? $prefixArg->compileTimeString;
        if (null === $lit) {
            return false;
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
}
