<?php

declare(strict_types=1);

namespace PHPCompiler\ext\dom;

use PHPCompiler\JIT\Builtin\Type\Object_;
use PHPCompiler\JIT\Builtin\Type\ObjectInstancePropertyLlvm;
use PHPCompiler\JIT\Variable as JITVariable;
use PHPLLVM\Value;

/**
 * LLVM lowering for DOMNode child/sibling edge properties after live mutation
 * (#18951, #28671, #33273).
 *
 * firstChild/lastChild stamps are absolute; nextSibling/previousSibling advance
 * the receiver's compile-time child index so replaceChild InnerXml splices the
 * correct sibling (middle via firstChild->nextSibling was replacing index 0).
 */
final class JitDomNodeChildProperty
{
    private const CLASS_NODE = 'DOMNode';

    /** Last firstChild/lastChild/nextSibling/previousSibling compile-time tag. */
    public static ?string $lastFetchedTagName = null;

    public static ?int $lastFetchedChildIndex = null;

    public static function isDomNodeChildProperty(string $classLc, string $propLc): bool
    {
        if (!\in_array(
            strtolower($propLc),
            ['firstchild', 'lastchild', 'nextsibling', 'previoussibling'],
            true
        )) {
            return false;
        }
        $classLc = strtolower($classLc);
        if (str_starts_with($classLc, 'dom')) {
            return true;
        }

        // documentElement temps often lose DOMElement userType (#23251 / #28671).
        return null !== JitDomLoadXMLUserScript::lastCompileTimeXml()
            && \in_array($classLc, ['object', 'stdclass', ''], true);
    }

    public static function fetch(
        Object_ $objectType,
        Value $obj,
        string $propName,
        string $classLc = 'domnode',
        ?JITVariable $receiverVar = null
    ): JITVariable {
        $slotClass = self::childEdgeClass($classLc);
        $result = ObjectInstancePropertyLlvm::propertyFetchDeclaredSlot(
            $objectType,
            $obj,
            $slotClass,
            $propName,
            $objectType->lookup($slotClass)
        );
        self::annotateCompileTimeChild($result, $propName, $receiverVar);
        $propLc = strtolower($propName);
        // GetNodePath's child-fetch annotator only knows first/last (defaults other
        // props to index 0) — do not let it wipe nextSibling/previousSibling stamps (#33273).
        if (!\in_array($propLc, ['nextsibling', 'previoussibling'], true)) {
            JitDomGetNodePath::annotateChildFetch($result, $propName);
        }

        return $result;
    }

    /**
     * Element allocations store first/last on DOMElement. Using DOMNode indices
     * on those objects aliases tagName/nodeName (#32361). Documents keep DOMNode.
     * appendChild() return temps are typed DOMNode but allocate as DOMElement.
     */
    private static function childEdgeClass(string $classLc): string
    {
        $classLc = strtolower(str_replace('/', '\\', ltrim($classLc, '\\')));
        if (str_contains($classLc, 'document') && !str_contains($classLc, 'element')) {
            return self::CLASS_NODE;
        }

        return 'DOMElement';
    }

    /**
     * Seed child index/tag from loadXML literal so replaceChild can rebuild
     * PROP_USER_SCRIPT_INNER_XML without collapsing siblings (#28671 / #33273).
     *
     * Prefer the *receiver* tree — lastCompileTimeXml is the globally last load and
     * is the destination document during cross-document importNode (#32978).
     */
    private static function annotateCompileTimeChild(
        JITVariable $result,
        string $propName,
        ?JITVariable $receiverVar = null
    ): void {
        if (!JitDomLoadXMLUserScript::lastLoadWasPureUserScript()) {
            return;
        }
        $nodes = self::compileTimeChildNodesForReceiver($receiverVar);
        if ([] === $nodes) {
            return;
        }
        $propLc = strtolower($propName);
        if ('firstchild' === $propLc) {
            self::stampChildIndex($result, $nodes, 0);

            return;
        }
        if ('lastchild' === $propLc) {
            self::stampChildIndex($result, $nodes, \count($nodes) - 1);

            return;
        }
        if ('nextsibling' === $propLc || 'previoussibling' === $propLc) {
            $base = $receiverVar?->compileTimeDomChildIndex
                ?? self::$lastFetchedChildIndex
                ?? null;
            if (null === $base) {
                return;
            }
            $index = 'nextsibling' === $propLc ? $base + 1 : $base - 1;
            if ($index < 0 || $index >= \count($nodes)) {
                return;
            }
            self::stampChildIndex($result, $nodes, $index);
        }
    }

    /**
     * Direct children of the property receiver (documentElement / element), not the
     * process-global last loadXML (destination wins after a second load).
     *
     * @return list<array{kind: string, data: string, inner?: string, open?: string}>
     */
    private static function compileTimeChildNodesForReceiver(?JITVariable $receiverVar): array
    {
        if (null !== $receiverVar) {
            $inner = $receiverVar->compileTimeDomInnerXml;
            if (null !== $inner && '' !== $inner) {
                return DomParseSimpleXmlJitHelper::parseSiblingNodesArgv($inner);
            }
            $bound = $receiverVar->compileTimeDomLoadXml
                ?? JitDomLoadXMLUserScript::compileTimeXmlFor($receiverVar);
            if (null !== $bound) {
                return DomParseSimpleXmlJitHelper::directChildNodesArgv($bound);
            }
        }
        $xml = JitDomLoadXMLUserScript::lastCompileTimeXml();
        if (null === $xml) {
            return [];
        }

        return DomParseSimpleXmlJitHelper::directChildNodesArgv($xml);
    }

    /**
     * @param list<array{kind: string, data: string, inner?: string, open?: string}> $nodes
     */
    private static function stampChildIndex(JITVariable $result, array $nodes, int $index): void
    {
        $result->compileTimeDomChildIndex = $index;
        self::$lastFetchedChildIndex = $index;
        if ('element' === ($nodes[$index]['kind'] ?? '')) {
            $result->compileTimeDomTagName = $nodes[$index]['data'];
            self::$lastFetchedTagName = $nodes[$index]['data'];
            $inner = $nodes[$index]['inner'] ?? null;
            if (null !== $inner) {
                $result->compileTimeDomInnerXml = $inner;
            }
        }
    }
}
