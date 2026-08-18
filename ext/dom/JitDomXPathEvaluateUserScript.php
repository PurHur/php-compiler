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
        // php:function() / php:functionString() after registerPhpFunctions (#27575).
        // Never host-fold these — unbound php: looks "invalid" on host Zend without registration.
        if (self::isPhpFunctionEvaluateExpr($expression)) {
            $phpFn = self::tryCompileTimePhpFunction($xml, $expression);
            if (null !== $phpFn) {
                return self::boxString($context, $phpFn);
            }

            return null;
        }
        // Invalid literal → warning + false before specialized folds (#22755 / #22721).
        $invalid = JitDomXPathQueryUserScript::tryHostInvalidExpressionFalse($context, $xml, $exprLit, 'evaluate');
        if (null !== $invalid) {
            return $invalid;
        }
        // XPath 1.0 `or` / `and` evaluate() — host-fold when Zend DOM is available (#32050).
        if (self::hasTopLevelOrAnd($expression)) {
            $host = self::tryHostEvaluateScalar($xml, $expression);
            if (null !== $host) {
                return self::boxHostScalar($context, $host);
            }
        }
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
        // namespace-uri([node-set]) — compile-time fold when XML literal is known (#21238).
        if (preg_match('~^namespace-uri\((.+)\)$~i', $expression, $nsWrap)) {
            $uri = self::namespaceUriForXPath($xml, trim($nsWrap[1]));
            if (null === $uri) {
                return null;
            }

            return self::boxString($context, $uri);
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

    /**
     * php:function("name", string(...)) / php:functionString(...) when registered (#27575).
     *
     * Requires registerNamespace("php", PHP_XPATH_NS) + registerPhpFunctions tracked
     * by {@see JitDomXPathRegisterUserScript}.
     */
    private static function isPhpFunctionEvaluateExpr(string $expression): bool
    {
        return 1 === preg_match('~^php:function(?:String)?\(~i', $expression);
    }

    private static function tryCompileTimePhpFunction(string $xml, string $expression): ?string
    {
        $phpNs = JitDomXPathRegisterUserScript::namespaceUri('php');
        if (DomConstants::PHP_XPATH_NS !== $phpNs) {
            return null;
        }
        // php:function("name", string(inner)) or php:functionString("name", //path)
        if (!preg_match(
            '~^php:(function(?:String)?)\(\s*["\']([^"\']+)["\']\s*,\s*(.+)\s*\)$~i',
            $expression,
            $m
        )) {
            return null;
        }
        $kind = strtolower($m[1]);
        $handler = $m[2];
        $argExpr = trim($m[3]);
        if (!JitDomXPathRegisterUserScript::isPhpFunctionAllowed($handler)) {
            return null;
        }
        if (!\is_callable($handler)) {
            return null;
        }
        $arg = null;
        if (preg_match('~^string\((.+)\)$~i', $argExpr, $stringWrap)) {
            $arg = self::stringForXPath($xml, trim($stringWrap[1]));
        } elseif ('functionstring' === $kind) {
            // functionString coerces node-set to string-value
            $arg = self::stringForXPath($xml, $argExpr);
        } else {
            return null;
        }
        if (null === $arg) {
            return null;
        }
        try {
            $result = $handler($arg);
        } catch (\Throwable) {
            return null;
        }
        if (\is_string($result)) {
            return $result;
        }
        if (null === $result) {
            return '';
        }
        if (\is_bool($result) || \is_int($result) || \is_float($result)) {
            return (string) $result;
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
        $count = DomParseSimpleXmlJitHelper::countXPathNameTestArgv(
            $xml,
            $tag,
            JitDomXPathRegisterUserScript::namespaces()
        );
        if (null === $count) {
            return null;
        }

        return $count > 0 ? $tag : '';
    }

    /**
     * First node namespace-uri for //tag or /* from compile-time XML (#21238).
     *
     * Prefixed paths match QName; unprefixed / * uses the default xmlns in scope.
     */
    private static function namespaceUriForXPath(string $xml, string $inner): ?string
    {
        $wantQname = null;
        $wantLocal = null;
        $firstOnly = false;
        if ('/*' === $inner || '/' === $inner) {
            $firstOnly = true;
        } elseif (preg_match('~^//([*\w][\w:-]*)$~', $inner, $matches)) {
            $tag = $matches[1];
            if ('*' === $tag) {
                return null;
            }
            $colon = strpos($tag, ':');
            if (false === $colon) {
                $wantLocal = $tag;
                $wantQname = $tag;
            } else {
                $wantQname = $tag;
                $wantLocal = substr($tag, $colon + 1);
            }
        } else {
            return null;
        }

        foreach (DomParseSimpleXmlJitHelper::walkElementsInScopeNamespaces($xml) as $element) {
            $qname = $element['qname'];
            $local = $element['local'];
            $prefix = $element['prefix'];
            $match = $firstOnly
                || (null !== $wantQname && 0 === strcasecmp($qname, $wantQname))
                || (null !== $wantLocal && 0 === strcasecmp($local, $wantLocal)
                    && null !== $wantQname && false === strpos($wantQname, ':'));
            if (!$match) {
                continue;
            }

            return $element['inScope'][$prefix] ?? '';
        }

        // No QName match in literal XML — let runtime ABI handle registerNamespace paths.
        return null;
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
        $count = DomParseSimpleXmlJitHelper::countXPathNameTestArgv(
            $xml,
            $tag,
            JitDomXPathRegisterUserScript::namespaces()
        );
        if (null === $count) {
            return null;
        }
        $sum = 0.0;
        // Prefer QName scan for text when unprefixed null-NS; fall back to literal tag walk.
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

    /**
     * Host Zend DOMXPath::evaluate() for compile-time scalar folds (#32050).
     *
     * @return bool|int|float|string|null
     */
    private static function tryHostEvaluateScalar(string $xml, string $expression): mixed
    {
        if (!\extension_loaded('dom') || !\class_exists(\DOMDocument::class, false)) {
            return null;
        }
        set_error_handler(static function (): bool {
            return true;
        });
        try {
            $doc = new \DOMDocument();
            if (!@$doc->loadXML($xml)) {
                restore_error_handler();

                return null;
            }
            $xpath = new \DOMXPath($doc);
            foreach (JitDomXPathRegisterUserScript::namespaces() as $prefix => $uri) {
                if ('' === $prefix) {
                    continue;
                }
                @$xpath->registerNamespace($prefix, $uri);
            }
            $result = $xpath->evaluate($expression);
        } catch (\Throwable) {
            restore_error_handler();

            return null;
        }
        restore_error_handler();
        if (\is_bool($result) || \is_int($result) || \is_float($result) || \is_string($result)) {
            return $result;
        }

        return null;
    }

    private static function hasTopLevelOrAnd(string $expression): bool
    {
        foreach (['or', 'and'] as $word) {
            $depth = 0;
            $quote = null;
            $len = \strlen($expression);
            $wordLen = \strlen($word);
            for ($i = 0; $i < $len; ++$i) {
                $ch = $expression[$i];
                if (null !== $quote) {
                    if ($ch === $quote) {
                        $quote = null;
                    }
                    continue;
                }
                if ('"' === $ch || "'" === $ch) {
                    $quote = $ch;
                    continue;
                }
                if ('(' === $ch || '[' === $ch) {
                    ++$depth;
                    continue;
                }
                if (')' === $ch || ']' === $ch) {
                    --$depth;
                    continue;
                }
                if (0 !== $depth) {
                    continue;
                }
                if ($i + $wordLen > $len || 0 !== substr_compare($expression, $word, $i, $wordLen)) {
                    continue;
                }
                $beforeOk = 0 === $i || !preg_match('/[\w.-]/', $expression[$i - 1]);
                $afterOk = $i + $wordLen >= $len || !preg_match('/[\w.-]/', $expression[$i + $wordLen]);
                if ($beforeOk && $afterOk) {
                    $left = trim(substr($expression, 0, $i));
                    $right = trim(substr($expression, $i + $wordLen));
                    if ('' !== $left && '' !== $right) {
                        return true;
                    }
                }
            }
        }

        return false;
    }

    private static function boxHostScalar(Context $context, bool|int|float|string $value): Value
    {
        if (\is_bool($value)) {
            $slot = JitValueBox::alloc($context);
            JitValueBox::writeBool(
                $context,
                $slot,
                $context->getTypeFromString('int1')->constInt($value ? 1 : 0, false)
            );

            return JitValueBox::normalizeValuePtr($context, $slot);
        }
        if (\is_int($value)) {
            return self::boxLong($context, $value);
        }
        if (\is_float($value)) {
            return self::boxDouble($context, $value);
        }

        return self::boxString($context, $value);
    }

    private static function countForXPath(string $xml, string $inner): ?int
    {
        // //tag[n] — at most one node (#19456).
        if (preg_match('~^//([*\w][\w:-]*)\[(\d+)\]$~', $inner, $posMatches)) {
            $text = DomParseSimpleXmlJitHelper::nthTagTextArgv($xml, $posMatches[1], (int) $posMatches[2]);

            return null === $text ? 0 : 1;
        }
        if (preg_match('~\s+(?:or|and)\s+|\[not\(~i', $inner)) {
            $host = self::tryHostEvaluateScalar($xml, 'count('.$inner.')');
            if (\is_int($host) || \is_float($host)) {
                return (int) $host;
            }
        }
        if (!preg_match(
            '~^//([*\w][\w:-]*)(?:\[@([^\]=]+)=(?:["\']([^"\']*)["\']|([+-]?(?:\d+\.?\d*|\.\d+)))\])?$~',
            $inner,
            $matches
        )) {
            return null;
        }
        $tag = $matches[1];
        $registeredNs = JitDomXPathRegisterUserScript::namespaces();
        if (!isset($matches[2]) || '' === $matches[2]) {
            return DomParseSimpleXmlJitHelper::countXPathNameTestArgv($xml, $tag, $registeredNs);
        }
        if (false !== strpos($tag, ':')) {
            return null;
        }
        $nsCount = DomParseSimpleXmlJitHelper::countXPathNameTestArgv($xml, $tag, $registeredNs);
        if (0 === $nsCount) {
            return 0;
        }
        if ($nsCount !== DomParseSimpleXmlJitHelper::countTagArgv($xml, $tag)) {
            return null;
        }
        $numeric = isset($matches[4]) && '' !== $matches[4];
        $attrValue = $numeric ? $matches[4] : ($matches[3] ?? '');
        $matched = DomParseSimpleXmlJitHelper::matchDescendantAttributeArgv(
            $xml,
            $tag,
            $matches[2],
            $attrValue,
            $numeric
        );

        return null === $matched ? 0 : $matched[0];
    }

    /**
     * First string-value for //@attr, //tag/@attr, //tag[n]/@attr, //tag[@a=v]/@attr,
     * //tag[@a=v], or //tag text (#19352, #21148, #24333).
     */
    private static function stringForXPath(string $xml, string $inner): ?string
    {
        if (preg_match('~^//@([\w.-]+)$~', $inner, $matches)) {
            return DomParseSimpleXmlJitHelper::firstAttributeValueArgv($xml, $matches[1]);
        }
        // //tag[@attr='v'|N]/@name — attribute axis after predicate (#21148, #24333).
        if (preg_match(
            '~^//([*\w][\w:-]*)\[@([^\]=]+)=(?:["\']([^"\']*)["\']|([+-]?(?:\d+\.?\d*|\.\d+)))\]/@([\w.-]+)$~',
            $inner,
            $matches
        )) {
            $numeric = isset($matches[4]) && '' !== $matches[4];
            $predValue = $numeric ? $matches[4] : ($matches[3] ?? '');

            return DomParseSimpleXmlJitHelper::matchingTagAttributeValueArgv(
                $xml,
                $matches[1],
                $matches[2],
                $predValue,
                $matches[5],
                $numeric
            );
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
        // //tag[@attr='v'|N] — element string-value (#21148, #24333).
        if (preg_match(
            '~^//([*\w][\w:-]*)\[@([^\]=]+)=(?:["\']([^"\']*)["\']|([+-]?(?:\d+\.?\d*|\.\d+)))\]$~',
            $inner,
            $matches
        )) {
            $numeric = isset($matches[4]) && '' !== $matches[4];
            $attrValue = $numeric ? $matches[4] : ($matches[3] ?? '');
            $matched = DomParseSimpleXmlJitHelper::matchDescendantAttributeArgv(
                $xml,
                $matches[1],
                $matches[2],
                $attrValue,
                $numeric
            );

            return null === $matched ? '' : $matched[1];
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
