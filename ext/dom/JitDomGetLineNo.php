<?php

declare(strict_types=1);

namespace PHPCompiler\ext\dom;

use PHPCompiler\JIT\BasicBlockHelper;
use PHPCompiler\JIT\Context;
use PHPCompiler\JIT\JitValueBox;
use PHPCompiler\JIT\Variable as JITVariable;
use PHPCompiler\VM\Builtin\VmClassMethod;
use PHPLLVM\Value;

/**
 * User-script AOT for DOMNode::getLineNo() (php-src xmlGetLineNo).
 *
 * Thin standalone AOT documentElement/firstChild temps lose DOMElement userType
 * and NestedJIT DomRegistry is empty — instance-invoke aborts as
 * object::getlineno(). Fold the compile-time loadXML source line into an int
 * (peer {@see JitDomGetNodePath}).
 *
 * php-src: ext/dom/node.c PHP_METHOD(DOMNode, getLineNo) (#32489)
 */
final class JitDomGetLineNo
{
    /** libxml xmlGetLineNo — 1-based line of $offset in $xml. */
    public static function lineNoAtOffset(string $xml, int $offset): int
    {
        if ($offset <= 0) {
            return 1;
        }

        return substr_count(substr($xml, 0, $offset), "\n") + 1;
    }

    /** Line of the document element start-tag (skip PI / DTD / comments). */
    public static function rootLineNo(string $xml): int
    {
        if (!preg_match('/<[A-Za-z_]/', $xml, $match, PREG_OFFSET_CAPTURE)) {
            return 1;
        }

        return self::lineNoAtOffset($xml, (int) $match[0][1]);
    }

    /**
     * Line of direct child $index under $inner (root inner XML).
     *
     * @param string $xml  full loadXML source
     * @param string $inner document-element inner markup
     */
    public static function childLineNo(string $xml, string $inner, int $index): int
    {
        $innerStart = strpos($xml, $inner);
        if (false === $innerStart) {
            return self::rootLineNo($xml);
        }
        $siblings = DomParseSimpleXmlJitHelper::parseSiblingNodesArgv($inner);
        $pos = 0;
        foreach ($siblings as $si => $sib) {
            $kind = $sib['kind'] ?? '';
            if ('element' === $kind) {
                $needle = $sib['open'] ?? '<'.($sib['data'] ?? '');
                $found = stripos($inner, $needle, $pos);
                if (false === $found) {
                    break;
                }
                if ($si === $index) {
                    return self::lineNoAtOffset($xml, $innerStart + $found);
                }
                $pos = $found + \strlen($needle);
            } elseif ('text' === $kind) {
                if ($si === $index) {
                    return self::lineNoAtOffset($xml, $innerStart + $pos);
                }
                $pos += \strlen((string) ($sib['data'] ?? ''));
            } elseif ('comment' === $kind) {
                $needle = '<!--'.($sib['data'] ?? '').'-->';
                $found = strpos($inner, $needle, $pos);
                if (false === $found) {
                    break;
                }
                if ($si === $index) {
                    return self::lineNoAtOffset($xml, $innerStart + $found);
                }
                $pos = $found + \strlen($needle);
            }
        }

        return self::rootLineNo($xml);
    }

    public static function invoke(Context $context, JITVariable ...$args): Value
    {
        BasicBlockHelper::ensureOpenInsertBlock($context, 'dom_getlineno_cont');
        if (!VmClassMethod::requireExactJitUserArgCount(
            $context,
            $args,
            'DOMNode::getLineNo',
            0
        )) {
            return VmClassMethod::jitArgcDummyReturn($context);
        }

        $receiver = $args[0] ?? null;
        if (null === $receiver) {
            throw new \LogicException('DOMNode::getLineNo() expects a receiver');
        }
        if (null !== $receiver->compileTimeDomLineNo) {
            return self::boxIntResult($context, $receiver->compileTimeDomLineNo);
        }
        $xml = JitDomLoadXMLUserScript::lastCompileTimeXmlSource();
        if (null !== $xml && JitDomLoadXMLUserScript::lastLoadWasPureUserScript()) {
            return self::boxIntResult($context, self::rootLineNo($xml));
        }

        throw new \LogicException(
            'DOMNode::getLineNo() user-script AOT requires compile-time loadXML'
        );
    }

    private static function boxIntResult(Context $context, int $value): Value
    {
        $slot = JitValueBox::alloc($context);
        JitValueBox::writeLong(
            $context,
            $slot,
            $context->getTypeFromString('int64')->constInt($value, true)
        );

        return JitValueBox::normalizeValuePtr($context, $slot);
    }
}
