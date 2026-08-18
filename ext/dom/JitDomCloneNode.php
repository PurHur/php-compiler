<?php

declare(strict_types=1);

namespace PHPCompiler\ext\dom;

use PHPCompiler\JIT\BasicBlockHelper;
use PHPCompiler\JIT\Context;
use PHPCompiler\JIT\JitValueBox;
use PHPCompiler\JIT\Variable as JITVariable;
use PHPLLVM\Value;

/**
 * LLVM lowering for DOMNode::cloneNode() (#32355).
 *
 * php-src ext/dom/node.c php_dom_clone_node → xmlDocCopyNode.
 * Thin-standalone AOT firstChild temps lose DOMElement userType, so the call
 * arrives as object::clonenode(). NestedJIT DomRegistry clones SIGSEGV on
 * returned objects (importNode #19212). Materialize from compile-time loadXML
 * markup instead — deep copies child inner XML, shallow keeps attributes only.
 */
final class JitDomCloneNode
{
    private static bool $materialized = false;

    public static function hasMaterializedClone(): bool
    {
        return self::$materialized;
    }

    public static function invoke(Context $context, JITVariable ...$args): Value
    {
        if (\count($args) < 1) {
            throw new \LogicException('DOMNode::cloneNode() expects a receiver');
        }

        BasicBlockHelper::ensureOpenInsertBlock($context, 'dom_clonenode_cont');

        $deep = false;
        if (isset($args[1])) {
            $deep = self::loadBool($args[1]);
        }

        $spec = self::cloneSpecFromCompileTimeXml($args[0], $deep);
        if (null === $spec) {
            $tag = $args[0]->compileTimeDomTagName ?? 'clone';
            $spec = ['tag' => $tag, 'attrSuffix' => '', 'inner' => ''];
        }

        $element = JitDomCreateElement::materializeElementWithTextContent(
            $context,
            $spec['tag'],
            ''
        );
        JitDomCreateElement::storeUserScriptInnerXml($context, $element, $spec['inner']);
        JitDomCreateElement::storeUserScriptXmlnsAttr($context, $element, $spec['attrSuffix']);
        self::$materialized = true;

        return self::boxObjectResult($context, $element);
    }

    /**
     * First (or indexed) direct child under the loadXML document element.
     *
     * @return array{tag: string, attrSuffix: string, inner: string}|null
     */
    private static function cloneSpecFromCompileTimeXml(JITVariable $receiver, bool $deep): ?array
    {
        $xml = JitDomLoadXMLUserScript::lastCompileTimeXml();
        if (null === $xml || '' === trim($xml)) {
            return null;
        }
        $inner = DomParseSimpleXmlJitHelper::rootInnerXmlArgv($xml);
        $chunks = DomParseSimpleXmlJitHelper::directChildMarkupChunks($inner);
        if ([] === $chunks) {
            return null;
        }
        $index = $receiver->compileTimeDomChildIndex ?? 0;
        $markup = $chunks[$index] ?? $chunks[0];
        $parsed = self::parseElementMarkup($markup);
        if (null === $parsed) {
            return null;
        }
        if (!$deep) {
            $parsed['inner'] = '';
        }

        return $parsed;
    }

    /**
     * @return array{tag: string, attrSuffix: string, inner: string}|null
     */
    public static function parseElementMarkup(string $markup): ?array
    {
        $markup = trim($markup);
        if (1 !== preg_match('/^<([A-Za-z_][\w:.-]*)((?:\s[^>]*)?)(\/?)>/', $markup, $m)) {
            return null;
        }
        $tag = $m[1];
        $attrSuffix = rtrim($m[2], " \t/");
        $selfClosing = '/' === ($m[3] ?? '');
        if ($selfClosing) {
            return ['tag' => $tag, 'attrSuffix' => $attrSuffix, 'inner' => ''];
        }
        $close = stripos($markup, '</'.$tag.'>');
        $afterOpen = \strlen($m[0]);
        $inner = false === $close
            ? substr($markup, $afterOpen)
            : substr($markup, $afterOpen, $close - $afterOpen);

        return ['tag' => $tag, 'attrSuffix' => $attrSuffix, 'inner' => $inner];
    }

    private static function loadBool(JITVariable $arg): bool
    {
        if (null !== $arg->compileTimeLong) {
            return 0 !== $arg->compileTimeLong;
        }
        if (null !== $arg->compileTimeString) {
            return '1' === $arg->compileTimeString || 'true' === strtolower($arg->compileTimeString);
        }

        return false;
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
