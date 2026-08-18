<?php

declare(strict_types=1);

namespace PHPCompiler\ext\dom;

use PHPCompiler\JIT\Builtin\Type\Object_;
use PHPCompiler\JIT\Builtin\Type\ObjectInstancePropertyLlvm;
use PHPCompiler\JIT\Variable as JITVariable;
use PHPLLVM\Value;

/** LLVM lowering for DOMNode::$firstChild / $lastChild after live mutation (#18951, #28671). */
final class JitDomNodeChildProperty
{
    private const CLASS_NODE = 'DOMNode';

    /** Last firstChild/lastChild compile-time tag — cloneNode receivers often lose Variable metadata. */
    public static ?string $lastFetchedTagName = null;

    public static ?int $lastFetchedChildIndex = null;

    public static function isDomNodeChildProperty(string $classLc, string $propLc): bool
    {
        if (!\in_array(strtolower($propLc), ['firstchild', 'lastchild'], true)) {
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

    public static function fetch(Object_ $objectType, Value $obj, string $propName, string $classLc = 'domnode'): JITVariable
    {
        $slotClass = self::childEdgeClass($classLc);
        $result = ObjectInstancePropertyLlvm::propertyFetchDeclaredSlot(
            $objectType,
            $obj,
            $slotClass,
            $propName,
            $objectType->lookup($slotClass)
        );
        self::annotateCompileTimeChild($result, $propName);

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
     * PROP_USER_SCRIPT_INNER_XML without collapsing siblings (#28671).
     */
    private static function annotateCompileTimeChild(JITVariable $result, string $propName): void
    {
        $xml = JitDomLoadXMLUserScript::lastCompileTimeXml();
        if (null === $xml || !JitDomLoadXMLUserScript::lastLoadWasPureUserScript()) {
            return;
        }
        $nodes = DomParseSimpleXmlJitHelper::directChildNodesArgv($xml);
        if ([] === $nodes) {
            return;
        }
        $propLc = strtolower($propName);
        if ('firstchild' === $propLc) {
            $result->compileTimeDomChildIndex = 0;
            self::$lastFetchedChildIndex = 0;
            if ('element' === $nodes[0]['kind']) {
                $result->compileTimeDomTagName = $nodes[0]['data'];
                self::$lastFetchedTagName = $nodes[0]['data'];
            }

            return;
        }
        if ('lastchild' === $propLc) {
            $last = \count($nodes) - 1;
            $result->compileTimeDomChildIndex = $last;
            self::$lastFetchedChildIndex = $last;
            if ('element' === $nodes[$last]['kind']) {
                $result->compileTimeDomTagName = $nodes[$last]['data'];
                self::$lastFetchedTagName = $nodes[$last]['data'];
            }
        }
    }
}
