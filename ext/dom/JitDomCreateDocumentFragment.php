<?php

declare(strict_types=1);

namespace PHPCompiler\ext\dom;

use PHPCompiler\JIT\BasicBlockHelper;
use PHPCompiler\JIT\Context;
use PHPCompiler\JIT\JitValueBox;
use PHPCompiler\JIT\Variable as JITVariable;
use PHPLLVM\Value;

/**
 * User-script AOT materialization for DOMDocument::createDocumentFragment() (#20203, #35168).
 *
 * Uses a DOMElement stand-in (same pattern as {@see JitDomCreateComment}) because
 * allocating an unregistered DOMDocumentFragment class aborts LLVM codegen in standalone AOT.
 * Stores ownerDocument = creating document so `$frag->ownerDocument === $doc` matches php-src.
 * Seeds empty textContent / INNER_XML so saveXML slot fetches are defined (#32334).
 * Stand-in must seed {@code nodeType=XML_DOCUMENT_FRAG_NODE} (#35168 peer #35148).
 *
 * Appended children are remembered for deep {@see JitDomImportNode} (#35881) — LiveMutation
 * must not concat InnerXml onto the fragment Variable (that doubled markup).
 *
 * php-src: ext/dom/document.c — dom_document_create_document_fragment
 */
final class JitDomCreateDocumentFragment
{
    /** True when the last createDocumentFragment() materialized (#35871). */
    public static bool $lastMaterialized = false;

    /**
     * Children appended to the last fragment (document order) for importNode (#35881).
     *
     * @var list<array{kind: string, tag?: string, data?: string, inner?: string, content?: string}>
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

        self::storeStringLiteral($context, $obj, self::PROP_NODE_NAME, '#document-fragment');
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
     * Record one appendChild/prepend arg onto the fragment for deep importNode (#35881).
     *
     * Prefer structured specs over InnerXml round-trip — syncUserScriptInnerXmlFromArgs
     * treated {@code #text} as {@code <#text/>} and doubled markup with JIT.php concat.
     */
    public static function rememberAppendedChild(JITVariable $child): void
    {
        $tag = $child->compileTimeDomTagName ?? null;
        if ('#text' === $tag) {
            self::$lastChildren[] = [
                'kind' => 'text',
                'data' => $child->compileTimeDomTextData ?? '',
            ];

            return;
        }
        if ('#comment' === $tag) {
            self::$lastChildren[] = [
                'kind' => 'comment',
                'data' => $child->compileTimeDomTextData ?? '',
            ];

            return;
        }
        if ('#cdata-section' === $tag) {
            self::$lastChildren[] = [
                'kind' => 'cdata',
                'data' => $child->compileTimeDomTextData ?? '',
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
            self::$lastChildren[] = [
                'kind' => 'element',
                'tag' => $tag,
                'data' => $tag,
                'inner' => $child->compileTimeDomInnerXml ?? '',
            ];

            return;
        }
        if (null !== $child->compileTimeDomTextData) {
            self::$lastChildren[] = [
                'kind' => 'text',
                'data' => $child->compileTimeDomTextData,
            ];
        }
    }

    /**
     * Prepend args (document order) onto {@see $lastChildren} (#35881).
     *
     * @param list<JITVariable> $children
     */
    public static function rememberPrependedChildren(array $children): void
    {
        $prefix = [];
        foreach ($children as $child) {
            $before = \count(self::$lastChildren);
            self::rememberAppendedChild($child);
            if (\count(self::$lastChildren) > $before) {
                $prefix[] = array_pop(self::$lastChildren);
            }
        }
        self::$lastChildren = array_merge($prefix, self::$lastChildren);
    }

    /**
     * Outer InnerXml for remembered children (xmlNodeDump of fragment children).
     *
     * @param list<array{kind: string, tag?: string, data?: string, inner?: string, content?: string}> $children
     */
    public static function innerXmlFromChildren(array $children): string
    {
        $parts = [];
        foreach ($children as $spec) {
            $kind = $spec['kind'] ?? '';
            if ('text' === $kind) {
                $parts[] = $spec['data'] ?? '';
            } elseif ('comment' === $kind) {
                $parts[] = '<!--'.($spec['data'] ?? '').'-->';
            } elseif ('cdata' === $kind) {
                $parts[] = '<![CDATA['.($spec['data'] ?? '').']]>';
            } elseif ('pi' === $kind) {
                $target = $spec['data'] ?? '';
                $content = $spec['content'] ?? '';
                $parts[] = '' === $content
                    ? '<?'.$target.'?>'
                    : '<?'.$target.' '.$content.'?>';
            } elseif ('element' === $kind) {
                $tag = $spec['tag'] ?? $spec['data'] ?? '';
                $inner = $spec['inner'] ?? '';
                $parts[] = '' === $inner
                    ? '<'.$tag.'/>'
                    : '<'.$tag.'>'.$inner.'</'.$tag.'>';
            }
        }

        return implode('', $parts);
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
