<?php

declare(strict_types=1);

namespace PHPCompiler\ext\standard;

use PHPCompiler\CompilerVersion;
use PHPCompiler\ext\tokenizer\LanguageScanner;
use PHPCompiler\ext\tokenizer\TokenConstantsData;

/**
 * Native syntax highlighter for highlight_string() / highlight_file() (#4824).
 *
 * php-src: Zend/zend_highlight.c — tokenizer → HTML color spans.
 * PHP 8.3+ wire format (GH-11913 / #24874): {@code <pre><code>}, literal spaces/newlines.
 * Pre-8.3: {@code <code><span>}, {@code &nbsp;} spaces, {@code <br />} newlines.
 */
final class HighlightEngine
{
    private const COLOR_DEFAULT = '#000000';

    private const COLOR_KEYWORD = '#007700';

    private const COLOR_HTML = '#0000BB';

    /** php-src php_highlight.h — STRING_COLOR for T_CONSTANT_ENCAPSED_STRING (#12401). */
    private const COLOR_STRING = '#DD0000';

    private const COLOR_COMMENT = '#FF8000';

    /** @var array<string, int> */
    private static ?array $tokenIds = null;

    /**
     * Zend highlight_file() on unreadable path with $return=true (ext/standard/url.c, #12032).
     *
     * Profile-gated empty shell (#24874).
     */
    public static function emptyHighlightHtml(): string
    {
        if (self::usesPreCodeWrapper()) {
            return '<pre><code style="color: '.self::COLOR_DEFAULT.'"></code></pre>';
        }

        return '<code><span style="color: '.self::COLOR_DEFAULT.'"></span>'."\n".'</code>';
    }

    /**
     * PHP 8.3+ highlight HTML (zend_highlight.c GH-11913).
     *
     * Default {@see CompilerVersion::VERSION} {@code 8.4.0-dev} compares ≥ 8.3.0, so the
     * default profile emits the modern wire format even when the host Zend is 8.2 (#24874).
     * Set {@code PHP_COMPILER_PROFILE=8.2} for the legacy {@code <code><span>} / {@code &nbsp;} shape.
     */
    public static function usesPreCodeWrapper(): bool
    {
        return version_compare(CompilerVersion::languageProfileVersion(), '8.3.0', '>=');
    }

    public static function render(string $code): string
    {
        if ('' === $code) {
            return self::emptyHighlightHtml();
        }
        $tokens = LanguageScanner::tokenize($code);
        $body = self::renderTokens($tokens);

        if (self::usesPreCodeWrapper()) {
            // php-src Zend/zend_highlight.c since 8.3 — <pre><code>, literal whitespace (#24874).
            return '<pre><code style="color: '.self::COLOR_DEFAULT.'">'.$body.'</code></pre>';
        }

        // Pre-8.3 — <code><span>, &nbsp; for spaces, <br /> for newlines (#24662 / #24750).
        return '<code><span style="color: '.self::COLOR_DEFAULT.'">'.$body.'</span>'."\n".'</code>';
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
        if ($id === $ids['T_CONSTANT_ENCAPSED_STRING']) {
            return self::COLOR_STRING;
        }
        if (\in_array(
            $id,
            [
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
        if ($id === $ids['T_INLINE_HTML']) {
            return self::COLOR_DEFAULT;
        }
        if ($id >= 256) {
            return self::COLOR_KEYWORD;
        }

        return self::COLOR_DEFAULT;
    }

    private static function escapeAndFormat(string $text): string
    {
        if (self::usesPreCodeWrapper()) {
            // PHP 8.3+: tabs → four literal spaces; spaces/newlines unchanged (GH-11913).
            $text = \str_replace("\t", '    ', $text);

            return \htmlspecialchars($text, \ENT_QUOTES | \ENT_SUBSTITUTE, 'UTF-8');
        }

        $escaped = \htmlspecialchars($text, \ENT_QUOTES | \ENT_SUBSTITUTE, 'UTF-8');
        $escaped = \str_replace("\t", '&nbsp;&nbsp;&nbsp;&nbsp;', $escaped);
        $escaped = \str_replace(' ', '&nbsp;', $escaped);
        $escaped = \str_replace("\n", '<br />', $escaped);

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
