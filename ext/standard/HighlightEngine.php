<?php

declare(strict_types=1);

namespace PHPCompiler\ext\standard;

use PHPCompiler\ext\tokenizer\LanguageScanner;
use PHPCompiler\ext\tokenizer\TokenConstantsData;

/**
 * Native syntax highlighter for highlight_string() / highlight_file() (#4824).
 *
 * php-src: ext/standard/php_highlight.h — tokenizer → HTML color spans.
 */
final class HighlightEngine
{
    private const COLOR_DEFAULT = '#000000';

    /** Zend highlight_file() on unreadable path with $return=true (ext/standard/url.c, #12032). */
    public const EMPTY_HIGHLIGHT_HTML = '<code><span style="color: #000000">'."\n".'</span>'."\n".'</code>';

    private const COLOR_KEYWORD = '#007700';

    private const COLOR_HTML = '#0000BB';

    private const COLOR_COMMENT = '#FF8000';

    /** @var array<string, int> */
    private static ?array $tokenIds = null;

    public static function render(string $code): string
    {
        $tokens = LanguageScanner::tokenize($code);
        $body = self::renderTokens($tokens);

        // php-src ext/standard/php_highlight.h — outer wrapper newlines match Zend byte-for-byte (#10308).
        return '<code><span style="color: '.self::COLOR_DEFAULT.'">'."\n".$body."\n".'</span>'."\n".'</code>';
    }

    /**
     * @param list<int|string|array{0: int, 1: string, 2: int}> $tokens
     */
    private static function renderTokens(array $tokens): string
    {
        $out = '';
        $lastColor = self::COLOR_DEFAULT;
        $spanText = '';
        $spanColor = null;

        $flushSpan = static function () use (&$out, &$spanText, &$spanColor): void {
            if (null === $spanColor || '' === $spanText) {
                $spanText = '';
                $spanColor = null;

                return;
            }
            $out .= '<span style="color: '.$spanColor.'">'.self::escapeAndFormat($spanText).'</span>';
            $spanText = '';
            $spanColor = null;
        };

        foreach ($tokens as $token) {
            if (\is_string($token)) {
                $text = $token;
                $color = self::colorForChar($text);
            } else {
                $text = $token[1];
                $color = self::colorForId((int) $token[0]);
            }
            if (self::isWhitespaceOnly($text)) {
                $color = $lastColor;
            } else {
                $lastColor = $color;
            }
            if (null !== $spanColor && $color !== $spanColor) {
                $flushSpan();
            }
            if (null === $spanColor) {
                $spanColor = $color;
            }
            $spanText .= $text;
        }
        $flushSpan();

        return $out;
    }

    private static function isWhitespaceOnly(string $text): bool
    {
        return '' === $text || '' === \trim($text, " \t\r\n\f\v");
    }

    private static function colorForChar(string $char): string
    {
        if (';' === $char || ',' === $char) {
            return self::COLOR_KEYWORD;
        }

        return self::COLOR_HTML;
    }

    private static function colorForId(int $id): string
    {
        $ids = self::tokenIds();

        if (\in_array($id, [$ids['T_OPEN_TAG'], $ids['T_OPEN_TAG_WITH_ECHO'], $ids['T_CLOSE_TAG']], true)) {
            return self::COLOR_HTML;
        }
        if (\in_array($id, [$ids['T_COMMENT'], $ids['T_DOC_COMMENT']], true)) {
            return self::COLOR_COMMENT;
        }
        if (\in_array(
            $id,
            [
                $ids['T_CONSTANT_ENCAPSED_STRING'],
                $ids['T_ENCAPSED_AND_WHITESPACE'],
                $ids['T_LNUMBER'],
                $ids['T_DNUMBER'],
            ],
            true
        )) {
            return self::COLOR_HTML;
        }
        if ($id === $ids['T_VARIABLE']) {
            return self::COLOR_DEFAULT;
        }
        if ($id >= 256) {
            return self::COLOR_KEYWORD;
        }

        return self::COLOR_DEFAULT;
    }

    private static function escapeAndFormat(string $text): string
    {
        $escaped = \htmlspecialchars($text, \ENT_QUOTES | \ENT_SUBSTITUTE, 'UTF-8');
        $escaped = \str_replace(' ', '&nbsp;', $escaped);
        $escaped = \str_replace("\t", '&nbsp;&nbsp;&nbsp;&nbsp;', $escaped);

        return $escaped;
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
