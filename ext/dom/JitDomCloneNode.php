<?php

declare(strict_types=1);

namespace PHPCompiler\ext\dom;

use PHPCompiler\JIT\BasicBlockHelper;
use PHPCompiler\JIT\Context;
use PHPCompiler\JIT\JitValueBox;
use PHPCompiler\JIT\Variable as JITVariable;
use PHPLLVM\Value;

/**
 * User-script AOT materialization for DOMNode::cloneNode() (php-src xmlDocCopyNode).
 *
 * Thin standalone AOT does not register {@see NodeCloneNode} on :object receivers
 * (firstChild temps lose DOMElement userType). Compile-time loadXML markup is the
 * SSOT — NestedJIT DomRegistry clone would SIGSEGV on the returned object like
 * importNode before the user-script materialize path (#19212).
 *
 * php-src: ext/dom/node.c php_dom_clone_node → xmlDocCopyNode (#32355)
 */
final class JitDomCloneNode
{
    /** Tag of the last materialized clone — JIT copies onto the result Variable. */
    public static ?string $lastResultTagName = null;

    public static function invoke(Context $context, JITVariable ...$args): Value
    {
        self::$lastResultTagName = null;
        if (\count($args) < 1) {
            throw new \LogicException('DOMNode::cloneNode() expects a receiver');
        }

        BasicBlockHelper::ensureOpenInsertBlock($context, 'dom_clone_node_cont');

        if (isset($args[1]) && $context->callerStrictTypes && JITVariable::TYPE_NULL === $args[1]->type) {
            \PHPCompiler\JIT\JitNativeString::ensureInsertBlock($context);
            \PHPCompiler\JIT\ExceptionBridge::emitTypeErrorAndAbort(
                $context,
                'DOMNode::cloneNode(): Argument #1 ($deep) must be of type bool, null given'
            );

            return self::boxNullResult($context);
        }

        $deep = self::compileTimeDeep($args[1] ?? null);
        $spec = self::compileTimeCloneSpec($args[0], $deep);
        if (null === $spec) {
            throw new \LogicException(
                'DOMNode::cloneNode() user-script AOT requires a compile-time loadXML tree'
            );
        }

        return self::materialize($context, $spec);
    }

    /**
     * @return null|array{kind: string, tag: string, attrs: string, inner: string, text: string}
     */
    private static function compileTimeCloneSpec(JITVariable $receiver, bool $deep): ?array
    {
        $xml = JitDomLoadXMLUserScript::lastCompileTimeXml();
        if (null === $xml || !JitDomLoadXMLUserScript::lastLoadWasPureUserScript()) {
            return null;
        }

        $index = $receiver->compileTimeDomChildIndex ?? JitDomNodeChildProperty::$lastFetchedChildIndex;
        $tagHint = $receiver->compileTimeDomTagName ?? JitDomNodeChildProperty::$lastFetchedTagName;
        $inner = DomParseSimpleXmlJitHelper::rootInnerXmlArgv($xml);
        $chunks = DomParseSimpleXmlJitHelper::directChildMarkupChunks($inner);
        if (null !== $index && isset($chunks[$index])) {
            return self::specFromMarkup($chunks[$index], $deep);
        }
        if (null !== $tagHint) {
            foreach ($chunks as $chunk) {
                $parsed = DomParseSimpleXmlJitHelper::parseElementMarkupArgv($chunk);
                if (null !== $parsed && strtolower($parsed['tag']) === strtolower($tagHint)) {
                    return self::specFromMarkup($chunk, $deep);
                }
            }
        }

        // documentElement / unannotated node: clone the document element.
        $tag = DomParseSimpleXmlJitHelper::rootTagArgv($xml);
        $attrs = self::rootAttrSuffix($xml);
        $childInner = $deep ? DomParseSimpleXmlJitHelper::rootInnerXmlArgv($xml) : '';
        $text = $deep ? DomParseSimpleXmlJitHelper::rootTextContentArgv($xml) : '';

        return [
            'kind' => 'element',
            'tag' => $tag,
            'attrs' => $attrs,
            'inner' => $childInner,
            'text' => $text,
        ];
    }

    /**
     * @return null|array{kind: string, tag: string, attrs: string, inner: string, text: string}
     */
    private static function specFromMarkup(string $markup, bool $deep): ?array
    {
        $parsed = DomParseSimpleXmlJitHelper::parseElementMarkupArgv($markup);
        if (null !== $parsed) {
            $inner = $deep ? $parsed['inner'] : '';
            $text = $deep ? DomParseSimpleXmlJitHelper::rootTextContentArgv($markup) : '';

            return [
                'kind' => 'element',
                'tag' => $parsed['tag'],
                'attrs' => $parsed['attrs'],
                'inner' => $inner,
                'text' => $text,
            ];
        }
        if (1 === preg_match('/^<!--(.*?)-->$/s', trim($markup), $comment)) {
            return [
                'kind' => 'comment',
                'tag' => '#comment',
                'attrs' => '',
                'inner' => '',
                'text' => $comment[1],
            ];
        }

        return [
            'kind' => 'text',
            'tag' => '#text',
            'attrs' => '',
            'inner' => '',
            'text' => $markup,
        ];
    }

    private static function rootAttrSuffix(string $xml): string
    {
        if (!preg_match('/<([a-zA-Z_][\w:.-]*)((?:\s[^>]*)?)(\/?)>/', $xml, $root)) {
            return '';
        }

        return rtrim($root[2] ?? '', " \t/");
    }

    /**
     * @param array{kind: string, tag: string, attrs: string, inner: string, text: string} $spec
     */
    private static function materialize(Context $context, array $spec): Value
    {
        self::$lastResultTagName = $spec['tag'];
        if ('comment' === $spec['kind']) {
            return JitDomCreateComment::materialize($context, $spec['text']);
        }
        if ('text' === $spec['kind']) {
            return JitDomCreateTextNode::materialize($context, $spec['text']);
        }

        $obj = JitDomCreateElement::materializeElementWithTextContent(
            $context,
            $spec['tag'],
            $spec['text']
        );
        JitDomCreateElement::storeUserScriptInnerXml($context, $obj, $spec['inner']);
        JitDomCreateElement::storeUserScriptXmlnsAttr($context, $obj, $spec['attrs']);

        return $obj;
    }

    private static function compileTimeDeep(?JITVariable $arg): bool
    {
        if (null === $arg) {
            return false;
        }
        if (null !== $arg->compileTimeLong) {
            return 0 !== $arg->compileTimeLong;
        }
        if (null !== $arg->compileTimeString) {
            return '1' === $arg->compileTimeString || 'true' === strtolower($arg->compileTimeString);
        }

        return false;
    }

    private static function boxNullResult(Context $context): Value
    {
        $slot = JitValueBox::alloc($context);
        $ptr = JitValueBox::pointer($context, $slot);
        $context->builder->call(
            $context->lookupFunction('__value__writeNull'),
            $ptr
        );

        return JitValueBox::normalizeValuePtr($context, $ptr);
    }
}
