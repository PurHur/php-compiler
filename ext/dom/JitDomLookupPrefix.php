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
 * User-script AOT for DOMNode::lookupPrefix() (php-src xmlSearchNsByHref).
 *
 * Thin standalone AOT documentElement/firstChild temps lose DOMElement userType
 * and NestedJIT DomRegistry is empty — instance-invoke aborts as
 * object::lookupprefix(). Fold compile-time loadXML xmlns decls (peer
 * {@see JitDomIsSupported}).
 *
 * php-src: ext/dom/node.c PHP_METHOD(DOMNode, lookupPrefix)
 *          libxml xmlSearchNsByHref — default xmlns (empty prefix) returns null (#32493)
 */
final class JitDomLookupPrefix
{
    public static function invoke(Context $context, JITVariable ...$args): Value
    {
        BasicBlockHelper::ensureOpenInsertBlock($context, 'dom_lookupprefix_cont');
        if (!VmClassMethod::requireExactJitUserArgCount(
            $context,
            $args,
            'DOMNode::lookupPrefix',
            1
        )) {
            return VmClassMethod::jitArgcDummyReturn($context);
        }

        $namespaceArg = $args[1];
        $namespace = JitStringBuiltinArg::compileTimeLiteral($namespaceArg)
            ?? $namespaceArg->compileTimeString;
        if (null === $namespace) {
            throw new \LogicException(
                'DOMNode::lookupPrefix() user-script AOT requires a compile-time namespace URI'
            );
        }

        $xml = JitDomLoadXMLUserScript::lastCompileTimeXml();
        if (null === $xml || !JitDomLoadXMLUserScript::lastLoadWasPureUserScript()) {
            throw new \LogicException(
                'DOMNode::lookupPrefix() user-script AOT requires compile-time loadXML'
            );
        }

        return self::boxNullableString(
            $context,
            self::prefixForUriInDocumentElementScope($xml, $namespace)
        );
    }

    /**
     * xmlSearchNsByHref from the document element (and inherited by firstChild).
     * Empty prefix is the default xmlns — php-src returns null when nsptr->prefix is NULL.
     */
    public static function prefixForUriInDocumentElementScope(string $xml, string $namespaceUri): ?string
    {
        if ('' === $namespaceUri) {
            return null;
        }
        $elements = DomParseSimpleXmlJitHelper::walkElementsInScopeNamespaces($xml);
        if ([] === $elements) {
            return null;
        }
        foreach ($elements[0]['inScope'] as $prefix => $uri) {
            if ('' === $prefix) {
                continue;
            }
            if ($uri === $namespaceUri) {
                return $prefix;
            }
        }

        return null;
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
