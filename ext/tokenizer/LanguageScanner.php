<?php

declare(strict_types=1);

namespace PHPCompiler\ext\tokenizer;

/**
 * Native PHP source lexer for token_get_all() (php-src Zend/zend_language_scanner.l; #4561).
 *
 * Returns Zend-compatible tuples: single-char strings or [token_id, text, line].
 *
 * With TOKEN_PARSE, tracks brace/bracket/paren nesting like Zend's nest_location_stack and
 * throws ParseError on mismatch / unclosed openers (ext/tokenizer/tokenizer.c; #26671).
 */
final class LanguageScanner
{
    public const TOKEN_PARSE = 1;

    private string $source;
    private int $len;
    private int $pos = 0;
    private int $line = 1;
    private int $flags;
    private bool $inPhp = false;

    /** @var list<int|string|array{0: int, 1: string, 2: int}> */
    private array $tokens = [];

    /**
     * Zend nest_location_stack entries when TOKEN_PARSE is set.
     *
     * @var list<array{0: string, 1: int}>
     */
    private array $nestStack = [];

    private function __construct(string $source, int $flags)
    {
        $this->source = $source;
        $this->len = \strlen($source);
        $this->flags = $flags;
    }

    /**
     * @return list<int|string|array{0: int, 1: string, 2: int}>
     */
    public static function tokenize(string $source, int $flags = 0): array
    {
        $scanner = new self($source, $flags);
        $scanner->scan();

        return $scanner->tokens;
    }

    private function scan(): void
    {
        while ($this->pos < $this->len) {
            if (!$this->inPhp) {
                if (!$this->scanInlineOrOpenTag()) {
                    break;
                }
                continue;
            }

            if ($this->tryCloseTag()) {
                continue;
            }

            $ch = $this->source[$this->pos];

            if ($this->isHorizontalOrVerticalSpace($ch)) {
                $this->scanWhitespace();
                continue;
            }

            if ('#' === $ch && $this->matchAt('#[')) {
                $this->pushToken($this->id('T_ATTRIBUTE'), '#[');
                // php-src: T_ATTRIBUTE "#["" enters '[' nesting under TOKEN_PARSE (#26671).
                $this->enterNesting('[');
                $this->pos += 2;
                continue;
            }
            if ('/' === $ch && $this->matchAt('//')) {
                $this->scanLineComment();
                continue;
            }
            if ('#' === $ch) {
                $this->scanLineComment();
                continue;
            }
            if ('/' === $ch && $this->matchAt('/*')) {
                $this->scanBlockComment();
                continue;
            }

            if ('"' === $ch || "'" === $ch) {
                $this->scanQuotedString($ch);
                continue;
            }

            if ('<' === $ch && $this->matchAt('<<<')) {
                $this->scanHeredoc();
                continue;
            }

            if ('$' === $ch) {
                $this->scanVariable();
                continue;
            }

            if ('(' === $ch && $this->tryCast()) {
                continue;
            }

            if ($this->tryMultiCharOperator()) {
                continue;
            }

            if ($this->tryNumber()) {
                continue;
            }

            if ($this->isLabelStart($ch)) {
                $this->scanIdentifierOrKeyword();
                continue;
            }

            if ('\\' === $ch) {
                $this->scanQualifiedName(true);
                continue;
            }

            if ('&' === $ch) {
                $this->scanAmpersand();
                continue;
            }

            if ('?' === $ch && $this->matchAt('?>')) {
                $this->tryCloseTag();
                continue;
            }

            if (\str_contains("()[]{};,!@^~+-*/%.:?=", $ch)) {
                $this->pushChar($ch);
                ++$this->pos;
                continue;
            }

            $this->pushToken($this->id('T_BAD_CHARACTER'), $ch);
            ++$this->pos;
        }

        $this->checkNestingAtEnd();
    }

    private function scanInlineOrOpenTag(): bool
    {
        $start = $this->pos;
        $startLine = $this->line;

        if ($this->matchAt('<?php')) {
            $this->pos += 5;
            $this->consumeOpenTagTrailingWhitespace();
            $this->pushToken($this->id('T_OPEN_TAG'), \substr($this->source, $start, $this->pos - $start), $startLine);
            $this->inPhp = true;

            return true;
        }

        if ($this->matchAt('<?=')) {
            $this->pos += 3;
            $this->pushToken($this->id('T_OPEN_TAG_WITH_ECHO'), '<?=', $startLine);
            $this->inPhp = true;

            return true;
        }

        if ($this->matchAt('<?') && !$this->matchAt('<?php') && !$this->matchAt('<?=')) {
            if (0 !== ($this->flags & self::TOKEN_PARSE)) {
                $this->pos += 2;
                $this->pushToken($this->id('T_OPEN_TAG'), '<?', $startLine);
                $this->inPhp = true;

                return true;
            }
        }

        if ($this->matchAt('<%') && 0 !== ($this->flags & self::TOKEN_PARSE)) {
            $this->pos += 2;
            $this->pushToken($this->id('T_OPEN_TAG'), '<%', $startLine);
            $this->inPhp = true;

            return true;
        }

        $next = $this->findNextOpenTag($this->pos);
        if (false === $next) {
            $text = \substr($this->source, $this->pos);
            if ('' !== $text) {
                $this->pushToken($this->id('T_INLINE_HTML'), $text, $startLine);
            }
            $this->pos = $this->len;

            return false;
        }

        if ($next > $this->pos) {
            $this->pushToken($this->id('T_INLINE_HTML'), \substr($this->source, $this->pos, $next - $this->pos), $startLine);
            $this->pos = $next;
        }

        return true;
    }

    /**
     * Next php-src open tag at/after $from (ext/tokenizer/tokenizer.c inline HTML scan; #18468).
     */
    private function findNextOpenTag(int $from): int|false
    {
        for ($pos = $from; $pos < $this->len; ++$pos) {
            if ('<' !== $this->source[$pos]) {
                continue;
            }
            if ($this->matchAt('<?php', $pos) || $this->matchAt('<?=', $pos)) {
                return $pos;
            }
            if (0 !== ($this->flags & self::TOKEN_PARSE)) {
                if ($this->matchAt('<?', $pos) && !$this->matchAt('<?php', $pos) && !$this->matchAt('<?=', $pos)) {
                    return $pos;
                }
                if ($this->matchAt('<%', $pos)) {
                    return $pos;
                }
            }
        }

        return false;
    }

    private function tryCloseTag(): bool
    {
        if (!$this->matchAt('?>')) {
            return false;
        }
        $startLine = $this->line;
        $this->pos += 2;
        $this->pushToken($this->id('T_CLOSE_TAG'), '?>', $startLine);
        $this->inPhp = false;

        return true;
    }

    /**
     * php-src Zend/zend_language_scanner.l — whitespace glued to T_OPEN_TAG (#21951).
     *
     * Same-line horizontal runs stay on the tag; a lone line break after `<?php` is included;
     * further whitespace (e.g. tab on the next line) is left for T_WHITESPACE.
     */
    private function consumeOpenTagTrailingWhitespace(): void
    {
        $consumedHorizontal = false;
        while ($this->pos < $this->len) {
            $ch = $this->source[$this->pos];
            if ($this->isOpenTagTrailingHorizontal($ch)) {
                ++$this->pos;
                $consumedHorizontal = true;
                continue;
            }
            break;
        }

        if ($consumedHorizontal || $this->pos >= $this->len) {
            return;
        }

        if ($this->consumeLineEndingAtOpenTag()) {
            return;
        }
    }

    private function isOpenTagTrailingHorizontal(string $ch): bool
    {
        return \str_contains(" \t\f\v", $ch);
    }

    private function consumeLineEndingAtOpenTag(): bool
    {
        if ($this->pos >= $this->len) {
            return false;
        }
        $ch = $this->source[$this->pos];
        if ("\r" === $ch) {
            ++$this->pos;
            if ($this->pos < $this->len && "\n" === $this->source[$this->pos]) {
                ++$this->pos;
            }
            ++$this->line;

            return true;
        }
        if ("\n" === $ch) {
            ++$this->pos;
            ++$this->line;

            return true;
        }

        return false;
    }

    private function scanWhitespace(): void
    {
        $start = $this->pos;
        $startLine = $this->line;
        while ($this->pos < $this->len) {
            $ch = $this->source[$this->pos];
            if (!$this->isHorizontalOrVerticalSpace($ch)) {
                break;
            }
            if ("\n" === $ch) {
                ++$this->line;
            }
            ++$this->pos;
        }
        // php-src ext/tokenizer/tokenizer.c: T_WHITESPACE is omitted only with TOKEN_SKIP_WHITESPACE (#9775).
        $this->pushToken($this->id('T_WHITESPACE'), \substr($this->source, $start, $this->pos - $start), $startLine);
    }

    private function scanLineComment(): void
    {
        $start = $this->pos;
        $startLine = $this->line;
        while ($this->pos < $this->len && "\n" !== $this->source[$this->pos]) {
            ++$this->pos;
        }
        $this->pushToken($this->id('T_COMMENT'), \substr($this->source, $start, $this->pos - $start), $startLine);
    }

    private function scanBlockComment(): void
    {
        $start = $this->pos;
        $startLine = $this->line;
        $this->pos += 2;
        while ($this->pos < $this->len) {
            if ("\n" === $this->source[$this->pos]) {
                ++$this->line;
            }
            if ('*' === $this->source[$this->pos] && $this->matchAt('*/', $this->pos)) {
                $this->pos += 2;
                break;
            }
            ++$this->pos;
        }
        $text = \substr($this->source, $start, $this->pos - $start);
        $type = \str_starts_with($text, '/**') ? $this->id('T_DOC_COMMENT') : $this->id('T_COMMENT');
        $this->pushToken($type, $text, $startLine);
    }

    private function scanQuotedString(string $quote): void
    {
        $start = $this->pos;
        $startLine = $this->line;
        ++$this->pos;

        if ("'" === $quote) {
            while ($this->pos < $this->len) {
                $ch = $this->source[$this->pos];
                if ("'" === $ch) {
                    ++$this->pos;
                    break;
                }
                if ('\\' === $ch && $this->pos + 1 < $this->len) {
                    $this->pos += 2;
                    continue;
                }
                if ("\n" === $ch) {
                    ++$this->line;
                }
                ++$this->pos;
            }
            $this->pushToken($this->id('T_CONSTANT_ENCAPSED_STRING'), \substr($this->source, $start, $this->pos - $start), $startLine);

            return;
        }

        $hasVar = false;
        while ($this->pos < $this->len) {
            $ch = $this->source[$this->pos];
            if ('"' === $ch) {
                break;
            }
            if ('$' === $ch || '{' === $ch) {
                $hasVar = true;
                break;
            }
            if ('\\' === $ch && $this->pos + 1 < $this->len) {
                $this->pos += 2;
                continue;
            }
            if ("\n" === $ch) {
                ++$this->line;
            }
            ++$this->pos;
        }

        if (!$hasVar) {
            if ($this->pos < $this->len) {
                ++$this->pos;
            }
            $this->pushToken($this->id('T_CONSTANT_ENCAPSED_STRING'), \substr($this->source, $start, $this->pos - $start), $startLine);

            return;
        }

        $this->pos = $start;
        ++$this->pos;
        $this->pushChar('"');
        while ($this->pos < $this->len && '"' !== $this->source[$this->pos]) {
            if ('$' === $this->source[$this->pos]) {
                if ($this->pos > $start + 1) {
                    $wsStart = $start + 1;
                    if ($wsStart < $this->pos) {
                        $this->pushToken($this->id('T_ENCAPSED_AND_WHITESPACE'), \substr($this->source, $wsStart, $this->pos - $wsStart), $startLine);
                    }
                }
                $this->scanVariable();
                $start = $this->pos - 1;
                continue;
            }
            if ('\\' === $this->source[$this->pos] && $this->pos + 1 < $this->len) {
                $this->pos += 2;
                continue;
            }
            if ("\n" === $this->source[$this->pos]) {
                ++$this->line;
            }
            ++$this->pos;
        }
        if ($this->pos < $this->len && '"' === $this->source[$this->pos]) {
            if ($this->pos > $start + 1) {
                $this->pushToken($this->id('T_ENCAPSED_AND_WHITESPACE'), \substr($this->source, $start + 1, $this->pos - $start - 1), $startLine);
            }
            $this->pushChar('"');
            ++$this->pos;
        }
    }

    private function scanHeredoc(): void
    {
        $start = $this->pos;
        $startLine = $this->line;
        while ($this->pos < $this->len && "\n" !== $this->source[$this->pos]) {
            ++$this->pos;
        }
        if ($this->pos < $this->len) {
            ++$this->pos;
            ++$this->line;
        }
        $header = \substr($this->source, $start, $this->pos - $start);
        $this->pushToken($this->id('T_START_HEREDOC'), $header, $startLine);

        if (!\preg_match('/<<<\\s*(\\\'?)([A-Za-z_][A-Za-z0-9_]*)\\1\\s*\\r?\\n/', $header, $m)) {
            return;
        }
        $label = $m[2];

        $bodyStart = $this->pos;
        $bodyLine = $this->line;
        while ($this->pos < $this->len) {
            if ($this->matchAt($label, $this->pos) && $this->isHeredocEnd($label)) {
                if ($bodyStart < $this->pos) {
                    $this->pushToken($this->id('T_ENCAPSED_AND_WHITESPACE'), \substr($this->source, $bodyStart, $this->pos - $bodyStart), $bodyLine);
                }
                $endStart = $this->pos;
                $endLine = $this->line;
                $this->pos += \strlen($label);
                $this->pushToken($this->id('T_END_HEREDOC'), \substr($this->source, $endStart, $this->pos - $endStart), $endLine);

                return;
            }
            if ("\n" === $this->source[$this->pos]) {
                ++$this->line;
            }
            ++$this->pos;
        }
        if ($bodyStart < $this->pos) {
            $this->pushToken($this->id('T_ENCAPSED_AND_WHITESPACE'), \substr($this->source, $bodyStart, $this->pos - $bodyStart), $bodyLine);
        }
    }

    private function isHeredocEnd(string $label): bool
    {
        $after = $this->pos + \strlen($label);
        if ($after >= $this->len) {
            return true;
        }
        $ch = $this->source[$after];

        return ';' === $ch || "\r" === $ch || "\n" === $ch;
    }

    private function scanVariable(): void
    {
        $start = $this->pos;
        $startLine = $this->line;
        ++$this->pos;
        if ($this->pos < $this->len && '{' === $this->source[$this->pos]) {
            $this->pushToken($this->id('T_DOLLAR_OPEN_CURLY_BRACES'), '${', $startLine);
            // php-src: "${" enters '{' nesting under TOKEN_PARSE (#26671).
            $this->enterNesting('{');
            $this->pos += 1;

            return;
        }
        // php-src zend_language_scanner.l: bare '$' when not followed by a variable label start.
        if ($this->pos >= $this->len || !$this->isLabelStart($this->source[$this->pos])) {
            $this->pos = $start;
            $this->pushChar('$');
            ++$this->pos;

            return;
        }
        while ($this->pos < $this->len && $this->isLabelContinue($this->source[$this->pos])) {
            ++$this->pos;
        }
        $this->pushToken($this->id('T_VARIABLE'), \substr($this->source, $start, $this->pos - $start), $startLine);
    }

    private function tryCast(): bool
    {
        $start = $this->pos;
        $startLine = $this->line;
        if ($start + 1 >= $this->len) {
            return false;
        }
        $close = \strpos($this->source, ')', $start + 1);
        if (false === $close || $close - $start > 12) {
            return false;
        }
        $tokenText = \substr($this->source, $start, $close - $start + 1);
        $castId = self::castMap()[\strtolower($tokenText)] ?? null;
        if (null === $castId) {
            return false;
        }
        $this->pos = $close + 1;
        $this->pushToken($castId, \substr($this->source, $start, $this->pos - $start), $startLine);

        return true;
    }

    private function tryNumber(): bool
    {
        $start = $this->pos;
        $startLine = $this->line;
        $ch = $this->source[$start];

        if ('.' === $ch) {
            if ($start + 1 >= $this->len || !\ctype_digit($this->source[$start + 1])) {
                return false;
            }
        } elseif (!\ctype_digit($ch)) {
            return false;
        }

        if ('0' === $ch && $start + 1 < $this->len) {
            $next = $this->source[$start + 1];
            if ('x' === $next || 'X' === $next) {
                $this->pos += 2;
                while ($this->pos < $this->len && ($this->isHexDigit($this->source[$this->pos]) || '_' === $this->source[$this->pos])) {
                    ++$this->pos;
                }
                $this->pushToken($this->id('T_LNUMBER'), \substr($this->source, $start, $this->pos - $start), $startLine);

                return true;
            }
            if ('b' === $next || 'B' === $next) {
                $this->pos += 2;
                while ($this->pos < $this->len && ('0' === $this->source[$this->pos] || '1' === $this->source[$this->pos] || '_' === $this->source[$this->pos])) {
                    ++$this->pos;
                }
                $this->pushToken($this->id('T_LNUMBER'), \substr($this->source, $start, $this->pos - $start), $startLine);

                return true;
            }
        }

        $seenDot = false;
        $seenExp = false;
        while ($this->pos < $this->len) {
            $c = $this->source[$this->pos];
            if (\ctype_digit($c) || '_' === $c) {
                ++$this->pos;
                continue;
            }
            if ('.' === $c && !$seenDot) {
                $seenDot = true;
                ++$this->pos;
                continue;
            }
            if (('e' === $c || 'E' === $c) && !$seenExp) {
                $seenExp = true;
                ++$this->pos;
                if ($this->pos < $this->len && ('+' === $this->source[$this->pos] || '-' === $this->source[$this->pos])) {
                    ++$this->pos;
                }
                continue;
            }
            break;
        }

        if ($start === $this->pos) {
            return false;
        }

        $text = \substr($this->source, $start, $this->pos - $start);
        $type = ($seenDot || $seenExp) ? $this->id('T_DNUMBER') : $this->id('T_LNUMBER');
        $this->pushToken($type, $text, $startLine);

        return true;
    }

    private function scanIdentifierOrKeyword(): void
    {
        $start = $this->pos;
        $startLine = $this->line;

        if ($this->matchAt('yield from')) {
            $after = $this->pos + 10;
            if ($after >= $this->len || !$this->isLabelContinue($this->source[$after])) {
                $this->pos += 10;
                $this->pushToken($this->id('T_YIELD_FROM'), 'yield from', $startLine);

                return;
            }
        }

        // php-src Zend/zend_language_scanner.l — "private(set)" / "public(set)" / "protected(set)"
        // as single tokens under PHP 8.4+ (#28130). No spaces inside the parentheses.
        if ($this->tryAsymmetricSetModifier($startLine)) {
            return;
        }

        while ($this->pos < $this->len && $this->isLabelContinue($this->source[$this->pos])) {
            ++$this->pos;
        }

        $text = \substr($this->source, $start, $this->pos - $start);
        $lower = \strtolower($text);

        if ('__halt_compiler' === $lower) {
            $this->pushToken($this->id('T_HALT_COMPILER'), $text, $startLine);

            return;
        }

        $magicId = self::magicConstantMap()[$lower] ?? null;
        if (null !== $magicId) {
            $this->pushToken($magicId, $text, $startLine);

            return;
        }

        $keywordId = self::keywordMap()[$lower] ?? null;
        if (null !== $keywordId) {
            $this->pushToken($keywordId, $text, $startLine);

            return;
        }

        if ($this->pos < $this->len && '\\' === $this->source[$this->pos]) {
            $this->scanQualifiedName(false, $start, $startLine, $text);

            return;
        }

        $this->pushToken($this->id('T_STRING'), $text, $startLine);
    }

    /**
     * php-src: Zend/zend_language_scanner.l T_PRIVATE_SET / T_PUBLIC_SET / T_PROTECTED_SET (#28130).
     *
     * Case-insensitive; spaces inside `(set)` are not allowed (falls through to T_PRIVATE + '(' …).
     */
    private function tryAsymmetricSetModifier(int $startLine): bool
    {
        if (!TokenConstants::usePhp84TokenizerSurface()) {
            return false;
        }

        static $literals = [
            'private(set)' => 'T_PRIVATE_SET',
            'protected(set)' => 'T_PROTECTED_SET',
            'public(set)' => 'T_PUBLIC_SET',
        ];

        foreach ($literals as $literal => $tokenName) {
            $len = \strlen($literal);
            if ($this->pos + $len > $this->len) {
                continue;
            }
            $slice = \substr($this->source, $this->pos, $len);
            if (0 !== \strcasecmp($slice, $literal)) {
                continue;
            }
            $this->pushToken($this->id($tokenName), $slice, $startLine);
            $this->pos += $len;

            return true;
        }

        return false;
    }

    private function scanQualifiedName(bool $fullyQualified, int $prefixStart = -1, int $prefixLine = -1, string $prefix = ''): void
    {
        $start = $prefixStart >= 0 ? $prefixStart : $this->pos;
        $startLine = $prefixLine >= 0 ? $prefixLine : $this->line;
        if ($prefixStart < 0) {
            ++$this->pos;
        }

        while ($this->pos < $this->len) {
            while ($this->pos < $this->len && $this->isLabelContinue($this->source[$this->pos])) {
                ++$this->pos;
            }
            if ($this->pos < $this->len && '\\' === $this->source[$this->pos]) {
                ++$this->pos;
                continue;
            }
            break;
        }

        $text = \substr($this->source, $start, $this->pos - $start);
        if ($fullyQualified || \str_starts_with($text, '\\')) {
            $type = $this->id('T_NAME_FULLY_QUALIFIED');
        } else {
            $type = \str_contains($text, '\\') ? $this->id('T_NAME_QUALIFIED') : $this->id('T_STRING');
        }
        $this->pushToken($type, $text, $startLine);
    }

    private function scanAmpersand(): void
    {
        $startLine = $this->line;
        $probe = $this->pos + 1;
        while ($probe < $this->len && $this->isHorizontalOrVerticalSpace($this->source[$probe])) {
            ++$probe;
        }
        $followed = $probe < $this->len && ('$' === $this->source[$probe] || $this->matchAt('...', $probe));
        $type = $followed
            ? $this->id('T_AMPERSAND_FOLLOWED_BY_VAR_OR_VARARG')
            : $this->id('T_AMPERSAND_NOT_FOLLOWED_BY_VAR_OR_VARARG');
        $this->pushToken($type, '&', $startLine);
        ++$this->pos;
    }

    private function tryMultiCharOperator(): bool
    {
        foreach (self::multiOpMap() as [$text, $id]) {
            if ($this->matchAt($text)) {
                $this->pushToken($id, $text, $this->line);
                $this->pos += \strlen($text);

                return true;
            }
        }

        return false;
    }

    private function matchAt(string $text, ?int $offset = null): bool
    {
        $offset ??= $this->pos;

        return 0 === \substr_compare($this->source, $text, $offset, \strlen($text));
    }

    private function isHorizontalOrVerticalSpace(string $ch): bool
    {
        return \str_contains(" \t\r\n\f\v", $ch);
    }

    private function isLabelStart(string $ch): bool
    {
        return \ctype_alpha($ch) || '_' === $ch;
    }

    private function isLabelContinue(string $ch): bool
    {
        return \ctype_alnum($ch) || '_' === $ch;
    }

    private function isHexDigit(string $ch): bool
    {
        return \ctype_xdigit($ch);
    }

    private function pushChar(string $ch): void
    {
        $this->tokens[] = $ch;
        if ('{' === $ch || '[' === $ch || '(' === $ch) {
            $this->enterNesting($ch);

            return;
        }
        if ('}' === $ch || ']' === $ch || ')' === $ch) {
            $this->exitNesting($ch);
        }
    }

    private function pushToken(int $id, string $text, ?int $line = null): void
    {
        $this->tokens[] = [$id, $text, $line ?? $this->line];
    }

    /**
     * php-src Zend/zend_language_scanner.l — enter_nesting() under PARSER_MODE / TOKEN_PARSE (#26671).
     */
    private function enterNesting(string $opening): void
    {
        if (0 === ($this->flags & self::TOKEN_PARSE)) {
            return;
        }
        $this->nestStack[] = [$opening, $this->line];
    }

    /**
     * php-src Zend/zend_language_scanner.l — exit_nesting() (#26671).
     */
    private function exitNesting(string $closing): void
    {
        if (0 === ($this->flags & self::TOKEN_PARSE)) {
            return;
        }
        if ([] === $this->nestStack) {
            throw new \ParseError("Unmatched '".$closing."'");
        }
        [$opening, $lineno] = $this->nestStack[\count($this->nestStack) - 1];
        $expect = '{' === $opening ? '}' : ('[' === $opening ? ']' : ')');
        if ($expect !== $closing) {
            throw new \ParseError($this->formatBadNesting($opening, $lineno, $closing));
        }
        \array_pop($this->nestStack);
    }

    /**
     * php-src Zend/zend_language_scanner.l — check_nesting_at_end() (#26671).
     */
    private function checkNestingAtEnd(): void
    {
        if (0 === ($this->flags & self::TOKEN_PARSE) || [] === $this->nestStack) {
            return;
        }
        [$opening, $lineno] = $this->nestStack[\count($this->nestStack) - 1];
        throw new \ParseError($this->formatBadNesting($opening, $lineno, ''));
    }

    /**
     * php-src Zend/zend_language_scanner.l — report_bad_nesting() (#26671).
     */
    private function formatBadNesting(string $opening, int $openingLineno, string $closing): string
    {
        $msg = "Unclosed '".$opening."'";
        if ($openingLineno !== $this->line) {
            $msg .= ' on line '.$openingLineno;
        }
        if ('' !== $closing) {
            $msg .= " does not match '".$closing."'";
        }

        return $msg;
    }

    private function id(string $name): int
    {
        static $maps = [];
        $key = TokenConstants::usePhp84TokenizerSurface() ? '84' : '82';
        if (!isset($maps[$key])) {
            $maps[$key] = TokenConstantsData::nameToId();
        }

        return $maps[$key][$name];
    }

    /** @return array<string, int> */
    private static function keywordMap(): array
    {
        static $byProfile = [];
        $key = TokenConstants::usePhp84TokenizerSurface() ? '84' : '82';
        if (isset($byProfile[$key])) {
            return $byProfile[$key];
        }

        $skip = [
            'T_STRING' => true,
            'T_VARIABLE' => true,
            'T_LNUMBER' => true,
            'T_DNUMBER' => true,
            'T_OPEN_TAG' => true,
            'T_CLOSE_TAG' => true,
            'T_WHITESPACE' => true,
            'T_COMMENT' => true,
            'T_DOC_COMMENT' => true,
            'T_INLINE_HTML' => true,
            'T_CONSTANT_ENCAPSED_STRING' => true,
            'T_ENCAPSED_AND_WHITESPACE' => true,
            'T_NAME_QUALIFIED' => true,
            'T_NAME_FULLY_QUALIFIED' => true,
            'T_NAME_RELATIVE' => true,
        ];

        $aliases = [
            'T_LOGICAL_AND' => 'and',
            'T_LOGICAL_OR' => 'or',
            'T_LOGICAL_XOR' => 'xor',
        ];

        $keywords = [];
        foreach (TokenConstantsData::nameToId() as $name => $id) {
            if (isset($skip[$name]) || !\str_starts_with($name, 'T_')) {
                continue;
            }
            if (isset($aliases[$name])) {
                $keywords[$aliases[$name]] = $id;
                continue;
            }
            if (\str_contains($name, '_EQUAL') || \str_contains($name, '_CAST') || \str_contains($name, 'OPERATOR')
                || \str_ends_with($name, '_SL') || \str_ends_with($name, '_SR') || \str_ends_with($name, '_INC')
                || \str_ends_with($name, '_DEC') || \str_starts_with($name, 'T_IS_') || \str_contains($name, 'T_SPACESHIP')
                || \str_contains($name, 'T_COALESCE') || \str_contains($name, 'T_POW') || \str_contains($name, 'T_ELLIPSIS')
                || \str_contains($name, 'T_DOUBLE_ARROW') || \str_contains($name, 'T_NS_SEPARATOR')
                || \str_contains($name, 'T_PAAMAYIM') || \str_contains($name, 'T_BAD_CHARACTER')
                || \str_contains($name, 'T_AMPERSAND') || \str_contains($name, 'T_ATTRIBUTE')
                || \str_contains($name, 'T_START_HEREDOC') || \str_contains($name, 'T_END_HEREDOC')
                || \str_contains($name, 'T_CURLY_OPEN') || \str_contains($name, 'T_DOLLAR_OPEN')
                || \str_contains($name, 'T_OPEN_TAG') || \str_contains($name, 'T_STRING_VARNAME')
                || \str_contains($name, 'T_NUM_STRING') || \str_contains($name, 'T_HALT_COMPILER')
                || \str_ends_with($name, '_C') || \str_ends_with($name, '_SET')
                || \str_contains($name, 'T_LINE') || \str_contains($name, 'T_FILE')
                || \str_contains($name, 'T_DIR') || \str_contains($name, 'T_YIELD_FROM')
            ) {
                continue;
            }
            $keywords[\strtolower(\substr($name, 2))] = $id;
        }

        $byProfile[$key] = $keywords;

        return $keywords;
    }

    /**
     * php-src Zend/zend_language_scanner.l magic constants (T_*_C / T_LINE / …; #28130).
     *
     * @return array<string, int>
     */
    private static function magicConstantMap(): array
    {
        static $byProfile = [];
        $key = TokenConstants::usePhp84TokenizerSurface() ? '84' : '82';
        if (isset($byProfile[$key])) {
            return $byProfile[$key];
        }

        $ids = TokenConstantsData::nameToId();
        $map = [
            '__line__' => $ids['T_LINE'],
            '__file__' => $ids['T_FILE'],
            '__dir__' => $ids['T_DIR'],
            '__class__' => $ids['T_CLASS_C'],
            '__trait__' => $ids['T_TRAIT_C'],
            '__method__' => $ids['T_METHOD_C'],
            '__function__' => $ids['T_FUNC_C'],
            '__namespace__' => $ids['T_NS_C'],
        ];
        if (isset($ids['T_PROPERTY_C'])) {
            $map['__property__'] = $ids['T_PROPERTY_C'];
        }
        $byProfile[$key] = $map;

        return $map;
    }

    /** @return list<array{0: string, 1: int}> */
    private static function multiOpMap(): array
    {
        static $byProfile = [];
        $key = TokenConstants::usePhp84TokenizerSurface() ? '84' : '82';
        if (isset($byProfile[$key])) {
            return $byProfile[$key];
        }

        $pairs = [
            ['??=', 'T_COALESCE_EQUAL'],
            ['??', 'T_COALESCE'],
            ['...', 'T_ELLIPSIS'],
            ['**=', 'T_POW_EQUAL'],
            ['**', 'T_POW'],
            ['?->', 'T_NULLSAFE_OBJECT_OPERATOR'],
            ['->', 'T_OBJECT_OPERATOR'],
            ['::', 'T_DOUBLE_COLON'],
            ['<<=', 'T_SL_EQUAL'],
            ['>>=', 'T_SR_EQUAL'],
            ['<<', 'T_SL'],
            ['>>', 'T_SR'],
            ['<=', 'T_IS_SMALLER_OR_EQUAL'],
            ['>=', 'T_IS_GREATER_OR_EQUAL'],
            ['<=>', 'T_SPACESHIP'],
            ['===', 'T_IS_IDENTICAL'],
            ['!==', 'T_IS_NOT_IDENTICAL'],
            ['==', 'T_IS_EQUAL'],
            ['!=', 'T_IS_NOT_EQUAL'],
            ['<>', 'T_IS_NOT_EQUAL'],
            ['++', 'T_INC'],
            ['--', 'T_DEC'],
            ['+=', 'T_PLUS_EQUAL'],
            ['-=', 'T_MINUS_EQUAL'],
            ['.=', 'T_CONCAT_EQUAL'],
            ['*=', 'T_MUL_EQUAL'],
            ['/=', 'T_DIV_EQUAL'],
            ['%=', 'T_MOD_EQUAL'],
            ['&=', 'T_AND_EQUAL'],
            ['|=', 'T_OR_EQUAL'],
            ['^=', 'T_XOR_EQUAL'],
            ['&&', 'T_BOOLEAN_AND'],
            ['||', 'T_BOOLEAN_OR'],
            ['=>', 'T_DOUBLE_ARROW'],
        ];

        $map = TokenConstantsData::nameToId();
        $ops = [];
        foreach ($pairs as [$text, $name]) {
            $ops[] = [$text, $map[$name]];
        }
        \usort($ops, static fn (array $a, array $b): int => \strlen($b[0]) <=> \strlen($a[0]));
        $byProfile[$key] = $ops;

        return $ops;
    }

    /** @return array<string, int> */
    private static function castMap(): array
    {
        static $byProfile = [];
        $key = TokenConstants::usePhp84TokenizerSurface() ? '84' : '82';
        if (isset($byProfile[$key])) {
            return $byProfile[$key];
        }

        $map = TokenConstantsData::nameToId();
        $casts = [
            '(int)' => $map['T_INT_CAST'],
            '(integer)' => $map['T_INT_CAST'],
            '(bool)' => $map['T_BOOL_CAST'],
            '(boolean)' => $map['T_BOOL_CAST'],
            '(float)' => $map['T_DOUBLE_CAST'],
            '(double)' => $map['T_DOUBLE_CAST'],
            '(real)' => $map['T_DOUBLE_CAST'],
            '(string)' => $map['T_STRING_CAST'],
            '(array)' => $map['T_ARRAY_CAST'],
            '(object)' => $map['T_OBJECT_CAST'],
            '(unset)' => $map['T_UNSET_CAST'],
        ];
        $byProfile[$key] = $casts;

        return $casts;
    }
}
