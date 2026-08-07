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

    public static function fetch(Object_ $objectType, Value $obj, string $propName): JITVariable
    {
        $result = ObjectInstancePropertyLlvm::propertyFetchDeclaredSlot(
            $objectType,
            $obj,
            self::CLASS_NODE,
            $propName,
            $objectType->lookup(self::CLASS_NODE)
        );
        self::annotateCompileTimeChild($result, $propName);

        return $result;
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
            if ('element' === $nodes[0]['kind']) {
                $result->compileTimeDomTagName = $nodes[0]['data'];
            }

            return;
        }
        if ('lastchild' === $propLc) {
            $last = \count($nodes) - 1;
            $result->compileTimeDomChildIndex = $last;
            if ('element' === $nodes[$last]['kind']) {
                $result->compileTimeDomTagName = $nodes[$last]['data'];
            }
        }
    }
}
