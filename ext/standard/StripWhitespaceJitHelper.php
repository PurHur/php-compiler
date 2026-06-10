<?php

declare(strict_types=1);

namespace PHPCompiler\ext\standard;

use PHPCompiler\ext\tokenizer\LanguageScanner;
use PHPCompiler\ext\tokenizer\TokenConstantsData;

/**
 * Lowered into JIT/AOT modules that call php_strip_whitespace() at runtime (#3262).
 *
 * php-src: Zend/zend_highlight.c — zend_strip()
 */
final class StripWhitespaceJitHelper
{
    /** @var array<string, int>|null */
    private static ?array $tokenIds = null;

    public static function stripString(string $code): string
    {
        $tokens = LanguageScanner::tokenize($code);
        $ids = self::tokenIds();
        $tWhitespace = $ids['T_WHITESPACE'];
        $tComment = $ids['T_COMMENT'];
        $tDocComment = $ids['T_DOC_COMMENT'];

        $out = '';
        $prevSpace = false;
        foreach ($tokens as $token) {
            if (\is_string($token)) {
                $out .= $token;
                $prevSpace = false;
                continue;
            }

            $id = (int) $token[0];
            $text = $token[1];
            if ($tWhitespace === $id) {
                if (!$prevSpace) {
                    $out .= ' ';
                    $prevSpace = true;
                }
                continue;
            }
            if ($tComment === $id || $tDocComment === $id) {
                continue;
            }

            $out .= $text;
            $prevSpace = false;
        }

        return $out;
    }

    /** @return array<string, int> */
    private static function tokenIds(): array
    {
        if (null === self::$tokenIds) {
            self::$tokenIds = TokenConstantsData::nameToId();
        }

        return self::$tokenIds;
    }
}
