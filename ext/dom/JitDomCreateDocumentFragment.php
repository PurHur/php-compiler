<?php

declare(strict_types=1);

namespace PHPCompiler\ext\dom;

use PHPCompiler\JIT\BasicBlockHelper;
use PHPCompiler\JIT\Context;
use PHPCompiler\JIT\JitValueBox;
use PHPCompiler\JIT\Variable as JITVariable;
use PHPLLVM\Value;
use PHPCompiler\ext\dom\JitDomCloneNode;

/**
 * User-script AOT materialization for DOMDocument::createDocumentFragment() (#20203, #35168).
 *
 * Uses a DOMElement stand-in (same pattern as {@see JitDomCreateComment}) because
 * allocating an unregistered DOMDocumentFragment class aborts LLVM codegen in standalone AOT.
 * Stores ownerDocument = creating document so `$frag->ownerDocument === $doc` matches php-src.
 * Seeds empty textContent / INNER_XML so saveXML slot fetches are defined (#32334).
 * Stand-in must seed {@code nodeType=XML_DOCUMENT_FRAG_NODE} (#35168 peer #35148).
 *
 * php-src: ext/dom/document.c — dom_document_create_document_fragment
 */
final class JitDomCreateDocumentFragment
{
    /** True when the last createDocumentFragment() materialized (#35871). */
    public static bool $lastMaterialized = false;

    public const TAG_KIND = '#document-fragment';

    /**
     * Compile-time children of the last createDocumentFragment tree (#35881 leftover of #35871).
     *
     * @var list<array{kind: string, data: string, content?: string, inner?: string}>
     */
    public static array $lastChildren = [];

    private const CLASS_STANDIN = 'DOMElement';

    private const PROP_NODE_NAME = 'nodeName';

    private const PROP_TEXT_CONTENT = 'textContent';

    private const PROP_OWNER_DOCUMENT = 'ownerDocument';

    public static function invoke(Context $context, JITVariable ...$args): Value
    {
        BasicBlockHelper::ensureOpenInsertBlock($context, 'dom_cdf_materialize_cont');
        if ([] === $args) {
            throw new \LogicException('DOMDocument::createDocumentFragment() called without $this');
        }

        $document = self::loadObjectArg($context, $args[0]);
        self::$lastMaterialized = true;
        self::$lastChildren = [];
        $objectType = $context->type->object;
        $classId = $objectType->lookup(self::CLASS_STANDIN);
        self::ensurePropertyLayout($objectType, $classId);

        $obj = $objectType->allocate($classId);
        $objectType->markObjectConstructed($obj);

        self::storeStringLiteral($context, $obj, self::PROP_NODE_NAME, self::TAG_KIND);
        // saveXML fetches textContent/INNER_XML on every node (#32315). Empty fragment
        // xmlNodeDump is "" (php-src ext/dom/document.c → xmlNewDocFragment).
        self::storeStringLiteral($context, $obj, self::PROP_TEXT_CONTENT, '');
        self::storeStringLiteral($context, $obj, VmDom::PROP_USER_SCRIPT_INNER_XML, '');
        // DOMNode::$ownerDocument is a TYPE_VALUE slot (nullable object); match declared layout.
        $ownerVar = new JITVariable($context, JITVariable::TYPE_OBJECT, JITVariable::KIND_VALUE, $document);
        $objectType->propertyStore(
            $objectType->propertySlotFor($obj, self::CLASS_STANDIN, self::PROP_OWNER_DOCUMENT),
            $ownerVar,
            JITVariable::TYPE_VALUE
        );
        // ParentNode nav on fragment stand-in (#35007 leftover of #34910).
        JitDomCreateElement::seedEmptyParentNodeNavigation($context, $obj, self::CLASS_STANDIN);
        // Stand-in is DOMElement class but nodeType must be DOCUMENT_FRAG (#35168).
        // Unset NATIVE_LONG nodeType SIGSEGVs on $frag->nodeType (peer entity-ref #35148).
        JitDomCreateElement::storeNodeType(
            $context,
            $obj,
            self::CLASS_STANDIN,
            DomConstants::XML_DOCUMENT_FRAG_NODE
        );

        return $obj;
    }


    /**
     * Refresh compile-time inner on an element already recorded in lastChildren (#35997).
     *
     * When a fragment child element gains text/nodes after appendChild onto the fragment,
     * importNode deep-copy must see the updated inner snapshot.
     */
    public static function refreshRecordedElementInner(JITVariable $element, string $inner): void
    {
        if (!self::$lastMaterialized || '' === $inner) {
            return;
        }
        $tag = $element->compileTimeDomTagName ?? null;
        if (null === $tag || '' === $tag || str_starts_with($tag, '#')) {
            return;
        }
        $count = \count(self::$lastChildren);
        for ($i = $count - 1; $i >= 0; --$i) {
            $node = self::$lastChildren[$i];
            if ('element' === ($node['kind'] ?? '') && $tag === ($node['data'] ?? '')) {
                self::$lastChildren[$i]['inner'] = $inner;

                return;
            }
        }
    }

    /** Record one appendChild onto the open createDocumentFragment (#35881). */
    public static function rememberAppendedChild(JITVariable $child): void
    {
        if (!self::$lastMaterialized) {
            return;
        }
        $tag = $child->compileTimeDomTagName ?? null;
        if ('#text' === $tag || (null === $tag && null !== $child->compileTimeDomTextData)) {
            self::$lastChildren[] = [
                'kind' => 'text',
                'data' => $child->compileTimeDomTextData
                    ?? JitDomCreateTextNode::$lastMaterializedData
                    ?? '',
            ];

            return;
        }
        if ('#comment' === $tag) {
            self::$lastChildren[] = [
                'kind' => 'comment',
                'data' => $child->compileTimeDomTextData
                    ?? JitDomCreateComment::$lastMaterializedData
                    ?? '',
            ];

            return;
        }
        if ('#cdata-section' === $tag) {
            self::$lastChildren[] = [
                'kind' => 'cdata',
                'data' => $child->compileTimeDomTextData
                    ?? JitDomCreateCDATASection::$lastMaterializedData
                    ?? '',
            ];

            return;
        }
        if (JitDomCreateProcessingInstruction::TAG_KIND === $tag) {
            self::$lastChildren[] = [
                'kind' => 'pi',
                'data' => $child->compileTimeDomAttributes['target']
                    ?? JitDomCreateProcessingInstruction::$lastMaterializedTarget
                    ?? '',
                'content' => $child->compileTimeDomTextData
                    ?? JitDomCreateProcessingInstruction::$lastMaterializedData
                    ?? '',
            ];

            return;
        }
        if (null !== $tag && '' !== $tag && !str_starts_with($tag, '#')) {
            $inner = $child->compileTimeDomInnerXml
                ?? JitDomCloneNode::$lastResultInnerXml
                ?? '';
            self::$lastChildren[] = [
                'kind' => 'element',
                'data' => $tag,
                'inner' => $inner,
            ];
        }
    }

    /**
     * Materialize one compile-time fragment child for expand/import (#35881 / #35518).
     *
     * @param array{kind: string, data: string, content?: string, inner?: string} $node
     */
    public static function materializeChildFromSpec(Context $context, array $node): ?Value
    {
        $kind = $node['kind'] ?? '';
        if ('comment' === $kind) {
            return JitDomCreateComment::materialize($context, $node['data']);
        }
        if ('cdata' === $kind) {
            return JitDomCreateCDATASection::materialize($context, $node['data']);
        }
        if ('pi' === $kind) {
            return JitDomCreateProcessingInstruction::materialize(
                $context,
                $node['data'],
                $node['content'] ?? ''
            );
        }
        if ('text' === $kind) {
            return JitDomCreateTextNode::materialize($context, $node['data'] ?? '');
        }
        if ('element' === $kind) {
            $tag = $node['data'] ?? '';
            $childInner = $node['inner'] ?? '';
            $text = '' === $childInner
                ? ''
                : DomParseSimpleXmlJitHelper::rootTextContentArgv(
                    '<'.$tag.'>'.$childInner.'</'.$tag.'>'
                );

            return JitDomCreateElement::materializeElementWithTextContent($context, $tag, $text);
        }

        return null;
    }

    /** Fresh DocumentFragment stand-in for importNode deep-copy (#35881). */
    public static function materialize(Context $context, JITVariable $documentVar): Value
    {
        // Keep lastChildren snapshot for the caller; only clear the open-tree flag.
        $saved = self::$lastChildren;
        $args = [$documentVar];
        $obj = self::invoke($context, ...$args);
        self::$lastChildren = $saved;

        return $obj;
    }

    private static function ensurePropertyLayout(
        \PHPCompiler\JIT\Builtin\Type\Object_ $objectType,
        int $classId
    ): void {
        // Full DOMElement stand-in layout (#33546 / #33556 / #33559 / #33564):
        // Document appendChild of a fragment previously SIGSEGV'd under thin AOT.
        JitDomCreateElement::ensureDomElementStandInLayout($objectType, $classId);
    }

    private static function storeStringLiteral(Context $context, Value $obj, string $prop, string $lit): void
    {
        $str = $context->builder->load($context->constantStringFromString($lit));
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
            $context->type->object->propertySlotFor($obj, self::CLASS_STANDIN, $prop),
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

        throw new \LogicException('DOMDocument::createDocumentFragment() receiver must be an object');
    }
}
