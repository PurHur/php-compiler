<?php

declare(strict_types=1);

namespace PHPCompiler\ext\dom;

use PHPCompiler\JIT\Context;
use PHPCompiler\JIT\JitStringBuiltinArg;
use PHPCompiler\JIT\JitValueBox;
use PHPCompiler\JIT\Variable as JITVariable;
use PHPLLVM\Value;

/** User-script standalone AOT: compile-time DOMXPath::evaluate() (#18526, #19352). */
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
            $inner = trim($boolWrap[1]);
            if (preg_match('~^count\((.+)\)$~i', $inner, $countWrap)) {
                $inner = trim($countWrap[1]);
            }
            $count = self::countForXPath($xml, $inner);
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
        if (preg_match('~^string\((.+)\)$~i', $expression, $stringWrap)) {
            $value = self::stringForXPath($xml, trim($stringWrap[1]));
            if (null === $value) {
                return null;
            }

            return self::boxString($context, $value);
        }
        if (preg_match('~^number\((.+)\)$~i', $expression, $numberWrap)) {
            $value = self::stringForXPath($xml, trim($numberWrap[1]));
            if (null === $value) {
                return null;
            }
            if ('' === $value || !is_numeric($value)) {
                return self::boxDouble($context, NAN);
            }

            return self::boxDouble($context, (float) $value);
        }
        if (preg_match('~^sum\((.+)\)$~i', $expression, $sumWrap)) {
            $sum = self::sumForXPath($xml, trim($sumWrap[1]));
            if (null === $sum) {
                return null;
            }

            return self::boxDouble($context, $sum);
        }
        // Comparisons / arithmetic / not() / name() — compile-time fold (#20280).
        if (preg_match('~^not\((.+)\)$~i', $expression, $notWrap)) {
            $count = self::countForXPath($xml, trim($notWrap[1]));
            if (null === $count) {
                return null;
            }

            return self::boxLong($context, 0 === $count ? 1 : 0);
        }
        if (preg_match('~^name\((.+)\)$~i', $expression, $nameWrap)) {
            $name = self::nameForXPath($xml, trim($nameWrap[1]));
            if (null === $name) {
                return null;
            }

            return self::boxString($context, $name);
        }
        $cmp = self::tryCompileTimeComparison($xml, $expression);
        if (null !== $cmp) {
            return self::boxLong($context, $cmp ? 1 : 0);
        }
        $arith = self::tryCompileTimeArithmetic($xml, $expression);
        if (null !== $arith) {
            return self::boxDouble($context, $arith);
        }

        return null;
    }

    /** First element name for //tag (#20280). */
    private static function nameForXPath(string $xml, string $inner): ?string
    {
        if (!preg_match('~^//([*\w][\w:-]*)$~', $inner, $matches)) {
            return null;
        }
        $tag = $matches[1];
        if ('*' === $tag) {
            return null;
        }
        $count = DomParseSimpleXmlJitHelper::countTagArgv($xml, $tag);

        return $count > 0 ? $tag : '';
    }

    private static function tryCompileTimeComparison(string $xml, string $expression): ?bool
    {
        if (!preg_match(
            '~^(count\((.+)\))\s*(=|!=|<=|>=|<|>)\s*([+-]?(?:\d+\.?\d*|\.\d+))$~i',
            $expression,
            $m
        ) && !preg_match(
            '~^([+-]?(?:\d+\.?\d*|\.\d+))\s*(=|!=|<=|>=|<|>)\s*([+-]?(?:\d+\.?\d*|\.\d+))$~',
            $expression,
            $m2
        )) {
            return null;
        }
        if (isset($m2)) {
            return self::cmpFloats((float) $m2[1], $m2[2], (float) $m2[3]);
        }
        $count = self::countForXPath($xml, trim($m[2]));
        if (null === $count) {
            return null;
        }

        return self::cmpFloats((float) $count, $m[3], (float) $m[4]);
    }

    private static function tryCompileTimeArithmetic(string $xml, string $expression): ?float
    {
        if (preg_match('~^([+-]?(?:\d+\.?\d*|\.\d+))\s*\+\s*([+-]?(?:\d+\.?\d*|\.\d+))$~', $expression, $m)) {
            return (float) $m[1] + (float) $m[2];
        }
        if (preg_match('~^count\((.+)\)\s*\+\s*([+-]?(?:\d+\.?\d*|\.\d+))$~i', $expression, $m)) {
            $count = self::countForXPath($xml, trim($m[1]));
            if (null === $count) {
                return null;
            }

            return (float) $count + (float) $m[2];
        }
        if (preg_match('~^([+-]?(?:\d+\.?\d*|\.\d+))$~', $expression, $m)) {
            return (float) $m[1];
        }

        return null;
    }

    private static function cmpFloats(float $left, string $op, float $right): bool
    {
        return match ($op) {
            '=' => $left === $right,
            '!=' => $left !== $right,
            '<' => $left < $right,
            '<=' => $left <= $right,
            '>' => $left > $right,
            '>=' => $left >= $right,
            default => false,
        };
    }

    /** XPath 1.0 sum(//tag) over compile-time XML (#19682). */
    private static function sumForXPath(string $xml, string $inner): ?float
    {
        if (!preg_match('~^//([*\w][\w:-]*)$~', $inner, $matches)) {
            return null;
        }
        $tag = $matches[1];
        $count = DomParseSimpleXmlJitHelper::countTagArgv($xml, $tag);
        $sum = 0.0;
        for ($i = 1; $i <= $count; ++$i) {
            $text = DomParseSimpleXmlJitHelper::nthTagTextArgv($xml, $tag, $i);
            if (null === $text) {
                return null;
            }
            if ('' === $text || !is_numeric($text)) {
                return NAN;
            }
            $sum += (float) $text;
        }

        return $sum;
    }

    private static function countForXPath(string $xml, string $inner): ?int
    {
        // //tag[n] — at most one node (#19456).
        if (preg_match('~^//([*\w][\w:-]*)\[(\d+)\]$~', $inner, $posMatches)) {
            $text = DomParseSimpleXmlJitHelper::nthTagTextArgv($xml, $posMatches[1], (int) $posMatches[2]);

            return null === $text ? 0 : 1;
        }
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

    /**
     * First string-value for //@attr, //tag/@attr, //tag[n]/@attr, or //tag text (#19352).
     */
    private static function stringForXPath(string $xml, string $inner): ?string
    {
        if (preg_match('~^//@([\w.-]+)$~', $inner, $matches)) {
            return DomParseSimpleXmlJitHelper::firstAttributeValueArgv($xml, $matches[1]);
        }
        if (preg_match('~^//([*\w][\w:-]*)(?:\[(\d+)\])?/@([\w.-]+)$~', $inner, $matches)) {
            $position = isset($matches[2]) && '' !== $matches[2] ? (int) $matches[2] : 1;

            return DomParseSimpleXmlJitHelper::nthTagAttributeValueArgv(
                $xml,
                $matches[1],
                $matches[3],
                $position
            );
        }
        // //tag or //tag[n] — element string-value (#19456).
        if (preg_match('~^//([*\w][\w:-]*)(?:\[(\d+)\])?$~', $inner, $matches)) {
            $position = isset($matches[2]) && '' !== $matches[2] ? (int) $matches[2] : 1;

            return DomParseSimpleXmlJitHelper::nthTagTextArgv($xml, $matches[1], $position);
        }

        return null;
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

    private static function boxString(Context $context, string $value): Value
    {
        $str = $context->builder->load($context->constantStringFromString($value));
        $owned = $context->builder->call(
            $context->lookupFunction('__string__separate'),
            $str
        );
        $slot = JitValueBox::alloc($context);
        $context->builder->call(
            $context->lookupFunction('__value__writeString'),
            JitValueBox::pointer($context, $slot),
            $owned
        );

        return JitValueBox::normalizeValuePtr($context, $slot);
    }
}
