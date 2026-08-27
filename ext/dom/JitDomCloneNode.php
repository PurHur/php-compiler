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
 * preferred SSOT; createElement + appendChild trees use {@see compileTimeDomTagName}
 * / {@see compileTimeDomInnerXml} instead (#35361). Mutation returns (appendChild /
 * insertBefore / replaceChild / removeChild) must propagate that metadata
 * ({@see JIT::propagateDomAppendChildCompileTimeTag}, #35373 / #35377 / #35386)
 * or cloneNode still aborts. importNode also stamps tag/inner/attrs (and may copy
 * compileTimeDomNodePath from the source); clone must prefer that Variable markup
 * over lastCompileTimeXml's document element (#35417 leftover of #35373). After a
 * loadXML mutation the Variable may still carry a pre-move child index — reject
 * that chunk when its tag disagrees with compileTimeDomTagName (#35425). NestedJIT
 * DomRegistry clone would SIGSEGV on the returned object like importNode before the
 * user-script materialize path (#19212).
 *
 * Materialize stores InnerXml for saveXML (#32355) and seeds LiveSlots via
 * {@see JitDomDocumentElement::syncChildrenFromXmlPublic} so firstChild walks
 * on the clone do not SIGSEGV (#32949).
 *
 * php-src: ext/dom/node.c php_dom_clone_node → xmlDocCopyNode (#32355, #32949, #35361, #35373, #35386, #35417, #35425)
 */
final class JitDomCloneNode
{
    /** Tag of the last materialized clone — JIT copies onto the result Variable. */
    public static ?string $lastResultTagName = null;

    /**
     * Markup of the most recent replaceChild/removeChild detached child (#35421).
     * documentElement re-fetch clears lastFetched* before the mutation, and the
     * loadXML SSOT is refreshed so chunk lookup no longer finds the detached node.
     */
    private static ?string $lastDetachedChildMarkup = null;

    /** Remember pre-mutation child markup for a later cloneNode on the return value. */
    public static function rememberDetachedChildMarkup(string $markup): void
    {
        $markup = trim($markup);
        self::$lastDetachedChildMarkup = '' === $markup ? null : $markup;
    }

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

        // Box as __value__* — raw __object__* temps make inline ?->tagName print empty
        // while non-nullsafe -> still works via compile-time tag (#34024 / peer #34019).
        return self::boxObjectResult($context, self::materialize($context, $spec));
    }

    /**
     * @return null|array{kind: string, tag: string, attrs: string, inner: string, text: string}
     */
    private static function compileTimeCloneSpec(JITVariable $receiver, bool $deep): ?array
    {
        $xml = JitDomLoadXMLUserScript::lastCompileTimeXml();
        if (null === $xml || !JitDomLoadXMLUserScript::lastLoadWasPureUserScript()) {
            // createElement / appendChild trees have no loadXML SSOT (#35361).
            return self::specFromCreateElementReceiver($receiver, $deep);
        }

        $index = $receiver->compileTimeDomChildIndex;
        $tagHint = $receiver->compileTimeDomTagName;
        // firstChild temps often drop Variable metadata — lastFetched* recovers them.
        // documentElement stamps compileTimeDomNodePath without a child index; do NOT
        // borrow lastFetchedChildIndex from a prior firstChild walk (#32949).
        if (null === $index && null === $receiver->compileTimeDomNodePath) {
            $index = JitDomNodeChildProperty::$lastFetchedChildIndex;
            $tagHint = $tagHint ?? JitDomNodeChildProperty::$lastFetchedTagName;
        }
        // Mutation returns may keep compileTimeDomNodePath while ARG_SEND dropped the
        // tag — still recover lastFetchedTagName so tag recovery can run (#35425).
        if ((null === $tagHint || '' === $tagHint)
            && null !== JitDomNodeChildProperty::$lastFetchedTagName
        ) {
            $tagHint = JitDomNodeChildProperty::$lastFetchedTagName;
        }

        $rootTag = DomParseSimpleXmlJitHelper::rootTagArgv($xml);

        // Detached replaceChild/removeChild child remembered *before* SSOT refresh
        // (#35421). Must run before index/chunk lookup: a stale childIndex still points
        // into the *post*-mutation tree (wrong sibling) after the refresh.
        if (null !== self::$lastDetachedChildMarkup) {
            $fromDetached = self::specFromMarkup(self::$lastDetachedChildMarkup, $deep);
            if (null !== $fromDetached) {
                // documentElement->cloneNode stamps nodePath + root tag and must not
                // steal a prior mutation's detached snapshot (#32949).
                $isDocumentElementClone = null !== $receiver->compileTimeDomNodePath
                    && null === $receiver->compileTimeDomChildIndex
                    && null !== $tagHint
                    && null !== $rootTag
                    && strtolower((string) $tagHint) === strtolower((string) $rootTag);
                if (!$isDocumentElementClone) {
                    self::$lastDetachedChildMarkup = null;

                    return $fromDetached;
                }
            }
        }

        $inner = DomParseSimpleXmlJitHelper::rootInnerXmlArgv($xml);
        $chunks = DomParseSimpleXmlJitHelper::directChildMarkupChunks($inner);
        if (null !== $index && isset($chunks[$index])) {
            // replaceChild/appendChild refresh the loadXML SSOT: a stale child index
            // then points at the wrong sibling (#35421 / #35425). Only trust the slot
            // when the tag still matches.
            $parsedAt = DomParseSimpleXmlJitHelper::parseElementMarkupArgv($chunks[$index]);
            $expectTag = $tagHint ?? JitDomNodeChildProperty::$lastFetchedTagName;
            if (
                null !== $expectTag
                && null !== $parsedAt
                && strtolower($parsedAt['tag']) === strtolower($expectTag)
            ) {
                return self::specFromMarkup($chunks[$index], $deep);
            }
            if (null === $expectTag && null === self::$lastDetachedChildMarkup) {
                return self::specFromMarkup($chunks[$index], $deep);
            }
        }
        if (null !== $tagHint && null !== $receiver->compileTimeDomChildIndex) {
            foreach ($chunks as $chunk) {
                $parsed = DomParseSimpleXmlJitHelper::parseElementMarkupArgv($chunk);
                if (null !== $parsed && strtolower($parsed['tag']) === strtolower($tagHint)) {
                    return self::specFromMarkup($chunk, $deep);
                }
            }
        }
        // Tag recovery must run even when compileTimeDomNodePath is set: importNode
        // copies the source path onto the detached result (#35417). Requiring
        // nodePath === null skipped this branch and fell through to cloning the
        // source documentElement (wrong tag/xml).
        if (null !== $tagHint) {
            foreach ($chunks as $chunk) {
                $parsed = DomParseSimpleXmlJitHelper::parseElementMarkupArgv($chunk);
                if (null !== $parsed && strtolower($parsed['tag']) === strtolower($tagHint)) {
                    return self::specFromMarkup($chunk, $deep);
                }
            }
            // Imported / createElement-shaped nodes carry their own markup on the
            // Variable — prefer that over lastCompileTimeXml's root.
            $fromRecv = self::specFromCreateElementReceiver($receiver, $deep);
            if (null !== $fromRecv) {
                return $fromRecv;
            }
        } elseif (null === $receiver->compileTimeDomNodePath) {
            // replaceChild/removeChild returns: ARG_SEND often drops Variable metadata
            // while lastFetched* still names the detached child. loadXML SSOT was
            // refreshed so chunk lookup misses — do not fall through to documentElement
            // (#35421 leftover of #35386).
            $fromRecv = self::specFromCreateElementReceiver($receiver, $deep);
            if (
                null !== $fromRecv
                && (
                    null === $rootTag
                    || strtolower($fromRecv['tag']) !== strtolower((string) $rootTag)
                )
            ) {
                return $fromRecv;
            }
        }

        // documentElement / unannotated node: clone the document element.
        $tag = $rootTag;
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
     * Deep/shallow clone when the node was built with createElement (+ appendChild),
     * not loadXML — php-src xmlDocCopyNode still applies (#35361).
     *
     * @return null|array{kind: string, tag: string, attrs: string, inner: string, text: string}
     */
    private static function specFromCreateElementReceiver(JITVariable $receiver, bool $deep): ?array
    {
        $tag = $receiver->compileTimeDomTagName
            ?? JitDomNodeChildProperty::$lastFetchedTagName
            ?? null;
        if (null === $tag || '' === $tag) {
            $textData = $receiver->compileTimeDomTextData
                ?? JitDomCreateTextNode::$lastMaterializedData
                ?? null;
            if (null !== $textData) {
                return [
                    'kind' => 'text',
                    'tag' => '#text',
                    'attrs' => '',
                    'inner' => '',
                    'text' => $textData,
                ];
            }

            return null;
        }
        if ('#comment' === $tag) {
            return [
                'kind' => 'comment',
                'tag' => '#comment',
                'attrs' => '',
                'inner' => '',
                'text' => $receiver->compileTimeDomTextData ?? '',
            ];
        }
        if ('#text' === $tag || '#cdata-section' === $tag) {
            return [
                'kind' => 'text',
                'tag' => '#text',
                'attrs' => '',
                'inner' => '',
                'text' => $receiver->compileTimeDomTextData ?? '',
            ];
        }

        $attrs = '';
        $attrMap = $receiver->compileTimeDomAttributes;
        $id = $receiver->compileTimeDomElementId ?? null;
        if (null !== $id) {
            $fromId = JitDomCreateElementAttrs::get($id);
            // Variable bag wins on conflict — side-table can lag when setAttribute's
            // receiver temp used lastId() of a newer createElement (#35386). Side-table
            // still fills keys the Variable snapshot never saw.
            if ([] !== $fromId) {
                $attrMap = null === $attrMap || [] === $attrMap
                    ? $fromId
                    : ($attrMap + $fromId);
            }
        }
        if (null === $attrMap || [] === $attrMap) {
            $fallbackId = $id ?? JitDomCreateElementAttrs::lastId();
            if (null !== $fallbackId) {
                $attrMap = JitDomCreateElementAttrs::get($fallbackId);
            }
        }
        if (null !== $attrMap && [] !== $attrMap) {
            $attrs = JitDomCreateElementAttrs::formatSuffix($attrMap);
        }
        $inner = $deep ? ($receiver->compileTimeDomInnerXml ?? '') : '';
        $openAttrs = '' === $attrs ? '' : (str_starts_with($attrs, ' ') ? $attrs : ' '.$attrs);
        $outer = '<'.$tag.$openAttrs.'>'.$inner.'</'.$tag.'>';
        $text = $deep ? DomParseSimpleXmlJitHelper::rootTextContentArgv($outer) : '';

        return [
            'kind' => 'element',
            'tag' => $tag,
            'attrs' => $attrs,
            'inner' => $inner,
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
        // saveXML reads InnerXml (#32355); firstChild/lastChild need LiveSlots like
        // loadXML documentElement (#19455 / #23251). Without this, deep-clone walks
        // SIGSEGV on AOT (re-#32355 / #32949).
        $attrs = $spec['attrs'];
        $openAttrs = '' === $attrs ? '' : (str_starts_with($attrs, ' ') ? $attrs : ' '.$attrs);
        $outer = '<'.$spec['tag'].$openAttrs.'>'.$spec['inner'].'</'.$spec['tag'].'>';
        // Pin Attrs on the clone so getAttribute reads this element's map — not the
        // process-global name→value cache (#34863 / peer loadXML #32956).
        JitDomCreateElement::storeAttributesPresence(
            $context,
            $obj,
            DomParseSimpleXmlJitHelper::attributesFromOpenTagArgv('<'.$spec['tag'].$openAttrs.'>')
        );
        JitDomDocumentElement::syncChildrenFromXmlPublic($context, $obj, $outer);

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
        $context->builder->call(
            $context->lookupFunction('__value__writeNull'),
            $ptr
        );

        return JitValueBox::normalizeValuePtr($context, $ptr);
    }
}
