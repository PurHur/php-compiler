<?php

declare(strict_types=1);

namespace PHPCompiler\ext\dom;

use PHPCompiler\JIT\Context;
use PHPCompiler\JIT\JitStringBuiltinArg;
use PHPCompiler\JIT\JitValueBox;
use PHPCompiler\JIT\Variable as JITVariable;
use PHPLLVM\Value;

/** User-script standalone AOT: compile-time DOMXPath::evaluate() (#18526). */
final class JitDomXPathEvaluateUserScript
{
    public static function shouldUse(Context $context): bool
    {
        return JitDomLoadHTMLUserScript::shouldUse($context);
    }

    public static function tryInvoke(Context $context, JITVariable ...$args): ?Value
    {
        if (\count($args) < 2) {
            return null;
        }
        $exprLit = JitStringBuiltinArg::compileTimeLiteral($args[1]) ?? $args[1]->compileTimeString;
        if (null === $exprLit) {
            return null;
        }
        $xml = JitDomLoadXMLUserScript::lastCompileTimeXml();
        if (null === $xml) {
            return null;
        }

        $expression = trim($exprLit);
        if (preg_match('~^boolean\((.+)\)$~i', $expression, $boolWrap)) {
            $count = self::countForXPath($xml, trim($boolWrap[1]));
            if (null === $count) {
                return null;
            }

            return self::boxLong($context, $count > 0 ? 1 : 0);
        }
        if (preg_match('~^count\((.+)\)$~i', $expression, $countWrap)) {
            $count = self::countForXPath($xml, trim($countWrap[1]));
            if (null === $count) {
                return null;
            }

            return self::boxDouble($context, (float) $count);
        }

        return null;
    }

    private static function countForXPath(string $xml, string $inner): ?int
    {
        if (!preg_match(
            '~^//([*\w][\w:-]*)(?:\[@([^\]=]+)=["\']([^"\']*)["\']\])?$~',
            $inner,
            $matches
        )) {
            return null;
        }
        $tag = $matches[1];
        if (!isset($matches[2])) {
            return DomParseSimpleXmlJitHelper::countTagArgv($xml, $tag);
        }

        $matched = DomParseSimpleXmlJitHelper::matchDescendantAttributeArgv(
            $xml,
            $tag,
            $matches[2],
            $matches[3]
        );

        return null === $matched ? 0 : $matched[0];
    }

    private static function boxLong(Context $context, int $value): Value
    {
        $slot = JitValueBox::alloc($context);
        $i64 = $context->getTypeFromString('int64');
        JitValueBox::writeLong(
            $context,
            $slot,
            $i64->constInt($value, false)
        );

        return JitValueBox::normalizeValuePtr($context, $slot);
    }

    private static function boxDouble(Context $context, float $value): Value
    {
        $slot = JitValueBox::alloc($context);
        $double = $context->getTypeFromString('double');
        $context->builder->call(
            $context->lookupFunction('__value__writeDouble'),
            JitValueBox::pointer($context, $slot),
            $double->constReal($value)
        );

        return JitValueBox::normalizeValuePtr($context, $slot);
    }
}
