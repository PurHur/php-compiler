<?php

declare(strict_types=1);

namespace PHPCompiler\Ast;

use PHPCompiler\CompilerVersion;
use PHPCompiler\HexFloat;
use PhpParser\Error as ParserError;

/**
 * Desugar PHP 8.4 hex float literals for nikic/php-parser on PHP < 8.4 hosts (#7041 / #29061).
 *
 * Gated on {@see CompilerVersion::supportsHexFloatLiterals()} (language profile ≥ 8.4.0) so
 * PROFILE≤8.3 / unset 8.4.0-dev leave the literal for the host parser to reject like Zend 8.2.
 *
 * php-src: Zend/zend_language_scanner.l — HNUM / hex-float tokenization
 */
final class HexFloatLiteralDesugar
{
    private const HEX_FLOAT = '~0x[0-9A-Fa-f_]+(?:\.[0-9A-Fa-f_]*)?[Pp][+-]?[0-9_]+~';

    /** Malformed hex-float only — plain hex integers (0xFF) must not match (#7140). */
    private const INVALID_SUFFIX = '~0x[0-9A-Fa-f_]+\.[0-9A-Fa-f_]*[a-oq-zA-OQ-Z]
        |0x[0-9A-Fa-f_]+(?:\.[0-9A-Fa-f_]*)?[Pp][+-]?[0-9_]*[a-oq-zA-OQ-Z]~x';

    private const INVALID_SUFFIX_MESSAGE = 'Invalid numeric literal';

    public static function desugar(string $code): string
    {
        if (!CompilerVersion::supportsHexFloatLiterals()) {
            return $code;
        }
        if (false === stripos($code, '0x')) {
            return $code;
        }

        $tokens = token_get_all($code);
        $replacements = [];
        $codeOffset = 0;

        for ($i = 0, $c = \count($tokens); $i < $c; ++$i) {
            $token = $tokens[$i];
            $tokenText = self::tokenText($token);
            $tokenLen = \strlen($tokenText);

            if (!\is_array($token) || T_LNUMBER !== $token[0] || !preg_match('/^0[xX]/', $tokenText)) {
                $codeOffset += $tokenLen;
                continue;
            }

            if (1 === preg_match(self::HEX_FLOAT, $code, $match, 0, $codeOffset)) {
                $literal = $match[0];
                $matchLen = \strlen($literal);
                $value = HexFloat::parse($literal);
                $replacements[] = [
                    'start' => $codeOffset,
                    'end' => $codeOffset + $matchLen,
                    'text' => HexFloat::toDecimalLiteral($value),
                ];
                $codeOffset += $matchLen;

                $consumed = $tokenLen;
                while ($consumed < $matchLen && $i + 1 < $c) {
                    ++$i;
                    $consumed += \strlen(self::tokenText($tokens[$i]));
                }
                continue;
            }

            if (1 === preg_match(self::INVALID_SUFFIX, $code, $invalidMatch, 0, $codeOffset)) {
                throw new ParserError(self::INVALID_SUFFIX_MESSAGE, [
                    'startLine' => $token[2],
                    'endLine' => $token[2],
                ]);
            }

            $codeOffset += $tokenLen;
        }

        if ([] === $replacements) {
            return $code;
        }

        usort($replacements, static fn (array $a, array $b): int => $b['start'] <=> $a['start']);
        foreach ($replacements as $replacement) {
            $code = substr($code, 0, $replacement['start'])
                .$replacement['text']
                .substr($code, $replacement['end']);
        }

        return $code;
    }

    /**
     * @param array{0: int, 1: string, 2: int}|string $token
     */
    private static function tokenText($token): string
    {
        return \is_array($token) ? $token[1] : $token;
    }
}
