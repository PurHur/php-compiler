<?php

declare(strict_types=1);

namespace PHPCompiler;

/**
 * PHP 8.4 hexadecimal floating-point literals (Zend/zend_strtod.c, issue #7041).
 *
 * Format: 0x[hexdigits](.[hexdigits])?[pP][+-]?[decimaldigits]
 */
final class HexFloat
{
    /** @var non-empty-string */
    private const PATTERN = '/^0x([0-9A-Fa-f]+)(?:\.([0-9A-Fa-f]*))?p([+-]?[0-9]+)$/i';

    public static function isLiteral(string $literal): bool
    {
        $literal = str_replace('_', '', $literal);

        return 1 === preg_match(self::PATTERN, $literal);
    }

    /**
     * @throws \InvalidArgumentException when $literal is not a hex float
     */
    public static function parse(string $literal): float
    {
        $literal = str_replace('_', '', $literal);
        if (!preg_match(self::PATTERN, $literal, $matches)) {
            throw new \InvalidArgumentException('Invalid hexadecimal floating-point literal');
        }

        $intPart = hexdec($matches[1]);
        $fracPart = 0.0;
        if (isset($matches[2]) && '' !== $matches[2]) {
            $fracPart = hexdec($matches[2]) / (16 ** \strlen($matches[2]));
        }

        $significand = $intPart + $fracPart;
        $exponent = (int) $matches[3];

        return $significand * (2 ** $exponent);
    }

    /** Decimal source text for php-parser T_DNUMBER replacement. */
    public static function toDecimalLiteral(float $value): string
    {
        if (is_nan($value)) {
            return 'NAN';
        }
        if ($value === INF) {
            return 'INF';
        }
        if ($value === -INF) {
            return '-INF';
        }

        $text = json_encode($value, JSON_PRESERVE_ZERO_FRACTION);
        if (!\is_string($text)) {
            return '0';
        }

        return $text;
    }
}
