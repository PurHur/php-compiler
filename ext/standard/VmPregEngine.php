<?php

declare(strict_types=1);

namespace PHPCompiler\ext\standard;

/**
 * Pure-PHP PCRE subset for VM bootstrap preg_* (#8935, #1492).
 *
 * php-src: ext/pcre/php_pcre.c — reference semantics; not a full PCRE2 port.
 */
final class VmPregEngine
{
    private static ?VmPregCompileException $lastCompileException = null;

    private static int $lastMatchLimitError = 0;

    private bool $compileAborted = false;

    /** @var 0|2|3|6 PREG_* limit error from match path; 0 = none */
    private int $limitErrorCode = 0;

    private const OPT_CASELESS = 0x00000008;

    private const OPT_DOTALL = 0x00000020;

    private const OPT_DOLLAR_ENDONLY = 0x00000010;

    private const OPT_EXTENDED = 0x00000080;

    private const OPT_MULTILINE = 0x00000400;

    private const OPT_UNGREEDY = 0x00040000;

    private const OPT_UTF = 0x00080000;

    private const OPT_ANCHORED = 0x80000000;

    /** PCRE2_DUPNAMES — PHP /J pattern modifier (ext/pcre/php_pcre.c, #17584). */
    private const OPT_DUPNAMES = 0x00100000;

    /** @var array<string, int> */
    private array $groupNameToIndex = [];

    private bool $caseless = false;

    private bool $dotall = false;

    private bool $dollarEndonly = false;

    private bool $extended = false;

    private bool $multiline = false;

    private bool $defaultGreedy = true;

    private bool $utf = false;

    private bool $anchored = false;

    /** PCRE2_DUPNAMES / `(?J)` — allow duplicate named subpatterns (ext/pcre/php_pcre.c, #17584). */
    private bool $allowDuplicateNames = false;

    private int $nextGroup = 1;

    private string $regex = '';

    private int $pos = 0;

    private int $backtrackCount = 0;

    private int $backtrackLimit = 0;

    private int $matchStart = 0;

    private ?VmPregAstNode $rootAst = null;

    private int $recursionDepth = 0;

    private int $jitStackLimit = 0;

    public function resetMatchStart(int $start): void
    {
        $this->matchStart = $start;
    }

    public function keepOutAt(int $pos): void
    {
        $this->matchStart = $pos;
    }

    public function matchStartPos(): int
    {
        return $this->matchStart;
    }

    public static function consumeLastCompileException(): ?VmPregCompileException
    {
        $exception = self::$lastCompileException;
        self::$lastCompileException = null;

        return $exception;
    }

    public static function consumeLastMatchLimitError(): int
    {
        $code = self::$lastMatchLimitError;
        self::$lastMatchLimitError = 0;

        return $code;
    }

    /**
     * @return array{0: VmPregAstNode, 1: array<string, int>, 2: int}|null
     */
    public static function compile(string $regex, int $opts): ?array
    {
        self::$lastCompileException = null;
        $engine = new self();
        $engine->applyOptions($opts);
        $ast = $engine->parsePattern($regex);
        if (null === $ast || $engine->compileAborted) {
            return null;
        }
        $engine->rootAst = $ast;

        return [$ast, $engine->groupNameToIndex, $engine->nextGroup - 1];
    }

    /**
     * @param array<string, int> $groupNameToIndex
     *
     * @return list<int>|null flat ovector [start0, end0, start1, end1, ...]; -1 for unset groups
     */
    public static function match(
        VmPregAstNode $ast,
        array $groupNameToIndex,
        string $subject,
        int $offset,
        int $opts,
        bool $anchoredAttempt
    ): array|null|false {
        $engine = new self();
        $engine->applyOptions($opts);
        $engine->groupNameToIndex = $groupNameToIndex;
        $engine->rootAst = $ast;
        $engine->backtrackLimit = VmPregLimits::backtrackLimit();
        $engine->jitStackLimit = VmPregLimits::jitStackLimit();
        $len = \strlen($subject);
        if ($offset < 0 || $offset > $len) {
            return null;
        }
        if ($engine->anchored || $anchoredAttempt) {
            $captures = [];
            $engine->resetMatchStart($offset);
            if ($engine->matchNode($ast, $subject, $offset, $len, $captures) && $captures[0][1] >= $offset) {
                $captures[0][0] = $engine->matchStartPos();

                return self::capturesToOvector($captures);
            }
            if (0 !== $engine->limitErrorCode) {
                self::$lastMatchLimitError = $engine->limitErrorCode;

                return false;
            }

            return null;
        }
        for ($start = $offset; $start <= $len; ) {
            $captures = [];
            $engine->resetMatchStart($start);
            if ($engine->matchNode($ast, $subject, $start, $len, $captures)) {
                $captures[0][0] = $engine->matchStartPos();

                return self::capturesToOvector($captures);
            }
            if (0 !== $engine->limitErrorCode) {
                self::$lastMatchLimitError = $engine->limitErrorCode;

                return false;
            }
            if ($start >= $len) {
                break;
            }
            // PCRE2_UTF: advance by code point so mid-sequence bytes are not start positions (#22003).
            if ($engine->utf) {
                $width = VmPregUtf8::utf8CharByteWidth($subject, $start);
                $start += $width > 0 ? $width : 1;
            } else {
                ++$start;
            }
        }

        return null;
    }

    /**
     * @param array<int, array{0: int, 1: int}> $captures
     *
     * @return list<int>
     */
    private static function capturesToOvector(array $captures): array
    {
        $max = 0;
        foreach ($captures as $k => $_) {
            if (\is_int($k) && $k > $max) {
                $max = $k;
            }
        }
        $out = [];
        for ($i = 0; $i <= $max; ++$i) {
            if (isset($captures[$i])) {
                $out[] = $captures[$i][0];
                $out[] = $captures[$i][1];
            } else {
                $out[] = -1;
                $out[] = -1;
            }
        }

        return $out;
    }

    public static function groupNumber(string $name, array $groupNameToIndex): int
    {
        return $groupNameToIndex[$name] ?? -1;
    }

    private function abortCompile(?string $msg = null, int $pos = 0): null
    {
        if (!$this->compileAborted) {
            self::$lastCompileException = new VmPregCompileException($msg ?? '', $pos);
            $this->compileAborted = true;
        }

        return null;
    }

    public function consumeLimitErrorCode(): int
    {
        $code = $this->limitErrorCode;
        $this->limitErrorCode = 0;

        return $code;
    }

    private function applyOptions(int $opts): void
    {
        $this->caseless = 0 !== ($opts & self::OPT_CASELESS);
        $this->dotall = 0 !== ($opts & self::OPT_DOTALL);
        $this->dollarEndonly = 0 !== ($opts & self::OPT_DOLLAR_ENDONLY);
        $this->extended = 0 !== ($opts & self::OPT_EXTENDED);
        $this->multiline = 0 !== ($opts & self::OPT_MULTILINE);
        $this->defaultGreedy = 0 === ($opts & self::OPT_UNGREEDY);
        $this->utf = 0 !== ($opts & self::OPT_UTF);
        $this->anchored = 0 !== ($opts & self::OPT_ANCHORED);
        $this->allowDuplicateNames = 0 !== ($opts & self::OPT_DUPNAMES);
    }

    private function parsePattern(string $regex): ?VmPregAstNode
    {
        $this->regex = $regex;
        $this->pos = 0;
        $this->nextGroup = 1;
        $this->groupNameToIndex = [];
        if ($this->atEnd()) {
            return new VmPregAstEmptyNode();
        }
        $node = $this->parseAlternation();
        if ($this->compileAborted || null === $node) {
            return null;
        }
        if (!$this->atEnd()) {
            $this->abortCompile('unexpected end of pattern', $this->pos);

            return null;
        }

        return $node;
    }

    private function parseAlternation(): VmPregAstNode
    {
        $branches = [$this->parseConcatenation()];
        while ($this->peek() === '|') {
            $this->advance(1);
            $branches[] = $this->parseConcatenation();
        }
        if (1 === \count($branches)) {
            return $branches[0];
        }

        return new VmPregAstAltNode($branches);
    }

    private function parseConcatenation(): VmPregAstNode
    {
        $parts = [];
        while (!$this->atEnd() && '|' !== $this->peek() && ')' !== $this->peek()) {
            if ($this->tryParseVerb()) {
                $parts[] = new VmPregAstEmptyNode();
                continue;
            }
            $parts[] = $this->parseQuantified();
        }
        if ([] === $parts) {
            return new VmPregAstEmptyNode();
        }
        if (1 === \count($parts)) {
            return $parts[0];
        }

        return new VmPregAstConcatNode($parts);
    }

    private function tryParseVerb(): bool
    {
        if ($this->peek() !== '(' || ($this->regex[$this->pos + 1] ?? '') !== '*') {
            return false;
        }
        $close = \strpos($this->regex, ')', $this->pos + 2);
        if (false === $close) {
            $this->abortCompile();

            return false;
        }
        $verb = \substr($this->regex, $this->pos + 2, $close - $this->pos - 2);
        if (!self::isRecognizedPcreVerb($verb)) {
            $this->abortCompile();

            return false;
        }
        self::applyPcreVerb($verb, $this);
        $this->pos = $close + 1;

        return true;
    }

    /**
     * @return bool true when verb is recognized (php-src ext/pcre/php_pcre.c, #12434)
     */
    private static function isRecognizedPcreVerb(string $verb): bool
    {
        return isset(self::PCRE_VERBS[$verb]);
    }

    private static function applyPcreVerb(string $verb, self $engine): void
    {
        match ($verb) {
            'UTF', 'UTF8', 'UTF16', 'UTF32' => $engine->utf = true,
            'UCP' => $engine->utf = true,
            'ANY', 'ALLANY' => $engine->dotall = true,
            'CR', 'LF', 'CRLF', 'ANYCRLF', 'BSR_ANYCRLF', 'BSR_UNICODE' => null,
            'NO_JIT', 'NO_START_OPT', 'NOTEMPTY', 'NOTEMPTY_ATSTART', 'FIRSTLINE', 'FRUSTRATING' => null,
            'ACCEPT', 'COMMIT', 'PRUNE', 'SKIP', 'THEN' => null,
            'FAIL' => null,
            default => $engine->abortCompile(),
        };
    }

    /** @var array<string, true> */
    private const PCRE_VERBS = [
        'ACCEPT' => true,
        'ALLANY' => true,
        'ANY' => true,
        'ANYCRLF' => true,
        'BSR_ANYCRLF' => true,
        'BSR_UNICODE' => true,
        'COMMIT' => true,
        'CR' => true,
        'CRLF' => true,
        'FAIL' => true,
        'FIRSTLINE' => true,
        'FRUSTRATING' => true,
        'LF' => true,
        'NO_JIT' => true,
        'NO_START_OPT' => true,
        'NOTEMPTY' => true,
        'NOTEMPTY_ATSTART' => true,
        'PRUNE' => true,
        'SKIP' => true,
        'THEN' => true,
        'UCP' => true,
        'UTF' => true,
        'UTF16' => true,
        'UTF32' => true,
        'UTF8' => true,
    ];

    private function parseQuantified(): VmPregAstNode
    {
        $base = $this->parseAtom();
        if ($this->atEnd()) {
            return $base;
        }
        $ch = $this->peek();
        if ('*' === $ch || '+' === $ch || '?' === $ch || '{' === $ch) {
            [$min, $max, $greedy] = $this->parseQuantifier();
            $base = new VmPregAstQuantNode($base, $min, $max, $greedy);

            return $base;
        }

        return $base;
    }

    /**
     * @return array{0: int, 1: int, 2: bool}
     */
    private function parseQuantifier(): array
    {
        $greedy = $this->defaultGreedy;
        $ch = $this->peek();
        if ('*' === $ch) {
            $this->advance(1);
            if ($this->peek() === '?') {
                $greedy = !$greedy;
                $this->advance(1);
            }

            return [0, \PHP_INT_MAX, $greedy];
        }
        if ('+' === $ch) {
            $this->advance(1);
            if ($this->peek() === '?') {
                $greedy = !$greedy;
                $this->advance(1);
            }

            return [1, \PHP_INT_MAX, $greedy];
        }
        if ('?' === $ch) {
            $this->advance(1);
            if ($this->peek() === '?') {
                $greedy = !$greedy;
                $this->advance(1);
            }

            return [0, 1, $greedy];
        }
        if ('{' === $ch) {
            $this->advance(1);
            $min = $this->parseDigits();
            $max = $min;
            if ($this->peek() === ',') {
                $this->advance(1);
                if (\ctype_digit($this->peek())) {
                    $max = $this->parseDigits();
                } else {
                    $max = \PHP_INT_MAX;
                }
            }
            if ($this->peek() !== '}') {
                $this->abortCompile();

                return [0, 0, $greedy];
            }
            $this->advance(1);
            if ($this->peek() === '?') {
                $greedy = !$greedy;
                $this->advance(1);
            }

            return [$min, $max, $greedy];
        }

        $this->abortCompile();

        return [0, 0, $greedy];
    }

    private function parseDigits(): int
    {
        if (!\ctype_digit($this->peek())) {
            $this->abortCompile();

            return 0;
        }
        $n = 0;
        while (\ctype_digit($this->peek())) {
            $n = $n * 10 + (int) $this->peek();
            $this->advance(1);
        }

        return $n;
    }

    private function parseAtom(): VmPregAstNode
    {
        $this->skipExtended();
        if ($this->atEnd()) {
            $this->abortCompile(); return new VmPregAstEmptyNode();
        }
        $ch = $this->peek();
        if ('(' === $ch) {
            return $this->parseGroup();
        }
        if ('[' === $ch) {
            return $this->parseClass();
        }
        if ('.' === $ch) {
            $this->advance(1);

            // PCRE2_UTF: `.` matches one code point (byte offsets still used in ovector) (#24785).
            return new VmPregAstAnyNode($this->dotall, $this->utf);
        }
        if ('^' === $ch) {
            $this->advance(1);

            return new VmPregAstBolNode($this->multiline);
        }
        if ('$' === $ch) {
            $this->advance(1);

            return new VmPregAstEolNode($this->multiline, $this->dollarEndonly);
        }
        if ('\\' === $ch) {
            return $this->parseEscape();
        }
        if ('|' === $ch || ')' === $ch) {
            $this->abortCompile(); return new VmPregAstEmptyNode();
        }
        $lit = $this->readLiteralChar();

        return new VmPregAstCharNode($lit, $this->caseless);
    }

    private function parseGroup(): VmPregAstNode
    {
        $openPos = $this->pos;
        $this->advance(1);
        $capture = true;
        $name = null;
        $nameCloseOffset = -1;
        if ($this->peek() === '?') {
            $this->advance(1);
            $flag = $this->peek();
            if (':' === $flag) {
                $capture = false;
                $this->advance(1);
            } elseif ('=' === $flag || '!' === $flag) {
                // Positive/negative lookahead (?=…) / (?!…) — PCRE2, php_pcre.c (#22002).
                $this->advance(1);
                $inner = $this->parseAlternation();
                if ($this->compileAborted) {
                    return new VmPregAstEmptyNode();
                }
                if ($this->peek() !== ')') {
                    $this->abortCompile('missing closing parenthesis', $openPos);

                    return new VmPregAstEmptyNode();
                }
                $this->advance(1);

                return new VmPregAstLookaheadNode($inner, '=' === $flag);
            } elseif ('>' === $flag) {
                // Atomic / possessive group (? > …) — no backtrack into inner (#22002).
                $this->advance(1);
                $inner = $this->parseAlternation();
                if ($this->compileAborted) {
                    return new VmPregAstEmptyNode();
                }
                if ($this->peek() !== ')') {
                    $this->abortCompile('missing closing parenthesis', $openPos);

                    return new VmPregAstEmptyNode();
                }
                $this->advance(1);

                return new VmPregAstAtomicNode($inner);
            } elseif ('#' === $flag) {
                $this->advance(1);
                while (!$this->atEnd() && ')' !== $this->peek()) {
                    $this->advance(1);
                }
                if ($this->peek() !== ')') {
                    $this->abortCompile(); return new VmPregAstEmptyNode();
                }
                $this->advance(1);

                return new VmPregAstEmptyNode();
            } elseif ('|' === $flag) {
                $this->advance(1);

                return $this->parseBranchResetAlternation();
            } elseif ('<' === $flag) {
                $this->advance(1);
                $behind = $this->peek();
                if ('=' === $behind || '!' === $behind) {
                    // Positive/negative lookbehind (?<=…) / (?<!…) (#22002).
                    $this->advance(1);
                    $inner = $this->parseAlternation();
                    if ($this->compileAborted) {
                        return new VmPregAstEmptyNode();
                    }
                    if ($this->peek() !== ')') {
                        $this->abortCompile('missing closing parenthesis', $openPos);

                        return new VmPregAstEmptyNode();
                    }
                    $this->advance(1);

                    return new VmPregAstLookbehindNode($inner, '=' === $behind);
                }
                $name = $this->parseGroupName();
                if ($this->peek() !== '>') {
                    $this->abortCompile(); return new VmPregAstEmptyNode();
                }
                $nameCloseOffset = $this->pos;
                $this->advance(1);
            } elseif ('P' === $flag) {
                $this->advance(1);
                if ($this->peek() !== '<') {
                    $this->abortCompile(); return new VmPregAstEmptyNode();
                }
                $this->advance(1);
                $name = $this->parseGroupName();
                if ($this->peek() !== '>') {
                    $this->abortCompile(); return new VmPregAstEmptyNode();
                }
                $nameCloseOffset = $this->pos;
                $this->advance(1);
            } elseif ('R' === $flag || '0' === $flag) {
                $this->advance(1);
                if ($this->peek() !== ')') {
                    $this->abortCompile(); return new VmPregAstEmptyNode();
                }
                $this->advance(1);

                return new VmPregAstRecursionNode();
            } elseif ($this->isInlineModifierStart($flag)) {
                $this->parseInlineModifiers();
                if ($this->peek() !== ')') {
                    $this->abortCompile(); return new VmPregAstEmptyNode();
                }
                $this->advance(1);

                return new VmPregAstEmptyNode();
            } else {
                $this->abortCompile(); return new VmPregAstEmptyNode();
            }
        }
        $index = null;
        if ($capture) {
            // php-src ext/pcre: capture numbers follow opening-paren order, not close order (#14574).
            $index = $this->nextGroup++;
            if (null !== $name) {
                if (isset($this->groupNameToIndex[$name])) {
                    $existing = $this->groupNameToIndex[$name];
                    if (!$this->allowDuplicateNames && $existing !== $index) {
                        $this->abortCompile(
                            'two named subpatterns have the same name (PCRE2_DUPNAMES not set)',
                            $nameCloseOffset >= 0 ? $nameCloseOffset : $openPos
                        );

                        return new VmPregAstEmptyNode();
                    }
                }
                $this->groupNameToIndex[$name] = $index;
            }
        }
        $inner = $this->parseAlternation();
        if ($this->compileAborted) {
            return new VmPregAstEmptyNode();
        }
        if ($this->peek() !== ')') {
            $this->abortCompile('missing closing parenthesis', $openPos);

            return new VmPregAstEmptyNode();
        }
        $this->advance(1);
        if (!$capture) {
            return $inner;
        }

        return new VmPregAstGroupNode($inner, $index);
    }

    /** PCRE branch-reset `(?|…)` — duplicate named groups per alternative (PHP 7.3+, ext/pcre/php_pcre.c). */
    private function parseBranchResetAlternation(): VmPregAstNode
    {
        $baseGroup = $this->nextGroup;
        $savedNames = $this->groupNameToIndex;
        $branches = [];
        while (true) {
            $this->nextGroup = $baseGroup;
            $this->groupNameToIndex = $savedNames;
            $branches[] = $this->parseConcatenation();
            if ('|' === $this->peek()) {
                $this->advance(1);
                continue;
            }
            break;
        }
        if (')' !== $this->peek()) {
            $this->abortCompile(); return new VmPregAstEmptyNode();
        }
        $this->advance(1);

        return new VmPregAstBranchResetAltNode($branches);
    }

    private function isInlineModifierStart(string $ch): bool
    {
        if ('-' === $ch || '+' === $ch) {
            return true;
        }

        return $this->isInlineModifierLetter($ch);
    }

    private function isInlineModifierLetter(string $ch): bool
    {
        return in_array($ch, ['i', 'm', 's', 'x', 'U', 'J', 'u', 'D', 'A'], true);
    }

    private function parseInlineModifiers(): void
    {
        while (!$this->atEnd() && ')' !== $this->peek()) {
            $ch = $this->peek();
            if ('-' === $ch || '+' === $ch) {
                $enable = '+' === $ch;
                $this->advance(1);
                if ($this->atEnd() || !$this->isInlineModifierLetter($this->peek())) {
                    $this->abortCompile();

                    return;
                }
                while (!$this->atEnd() && $this->isInlineModifierLetter($this->peek())) {
                    $this->applyInlineModifier($this->peek(), $enable);
                    $this->advance(1);
                }
                continue;
            }
            if (!$this->isInlineModifierLetter($ch)) {
                $this->abortCompile();

                return;
            }
            $this->applyInlineModifier($ch, true);
            $this->advance(1);
        }
    }

    private function applyInlineModifier(string $letter, bool $enable): void
    {
        match ($letter) {
            'i' => $this->caseless = $enable,
            'm' => $this->multiline = $enable,
            's' => $this->dotall = $enable,
            'x' => $this->extended = $enable,
            'U' => $this->defaultGreedy = !$enable,
            'J' => $this->allowDuplicateNames = $enable, // PCRE2_DUPNAMES (#17584)
            'u' => $this->utf = $enable,
            'D' => $this->dollarEndonly = $enable,
            'A' => $this->anchored = $enable,
            default => $this->abortCompile(),
        };
    }

    private function parseGroupName(): string
    {
        $ch = $this->peek();
        if ('' === $ch || !self::isNameStart($ch)) {
            $this->abortCompile();

            return '';
        }
        $start = $this->pos;
        $this->advance(1);
        while ($this->pos < \strlen($this->regex) && self::isNameChar($this->peek())) {
            $this->advance(1);
        }

        return \substr($this->regex, $start, $this->pos - $start);
    }

    private static function isNameStart(string $ch): bool
    {
        $o = \ord($ch);

        return ($o >= 65 && $o <= 90) || ($o >= 97 && $o <= 122) || '_' === $ch;
    }

    private static function isNameChar(string $ch): bool
    {
        if (self::isNameStart($ch)) {
            return true;
        }
        $o = \ord($ch);

        return $o >= 48 && $o <= 57;
    }

    private function parseClass(): VmPregAstNode
    {
        $openPos = $this->pos;
        $this->advance(1);
        $negated = false;
        if ($this->peek() === '^') {
            $negated = true;
            $this->advance(1);
        }
        $ranges = [];
        while (!$this->atEnd() && ']' !== $this->peek()) {
            if ('\\' === $this->peek()) {
                $ranges = \array_merge($ranges, $this->classEscape());
                continue;
            }
            $start = $this->readLiteralChar();
            if ($this->peek() === '-' && $this->pos + 1 < \strlen($this->regex) && ']' !== $this->regex[$this->pos + 1]) {
                $this->advance(1);
                $end = $this->readLiteralChar();
                $ranges[] = [$start, $end];
            } else {
                $ranges[] = [$start, $start];
            }
        }
        if ($this->peek() !== ']') {
            $this->abortCompile('missing terminating ] for character class', $openPos); return new VmPregAstEmptyNode();
        }
        $this->advance(1);

        return new VmPregAstClassNode($ranges, $negated, $this->caseless);
    }

    /**
     * @return list<array{0: string, 1: string}>
     */
    private function classEscape(): array
    {
        $this->advance(1);
        if ($this->atEnd()) {
            $this->abortCompile();

            return [];
        }
        $ch = $this->peek();
        if ('x' === $ch) {
            $this->advance(1);
            $bytes = $this->parseHexEscapeBytes();
            if (null === $bytes || 1 !== \strlen($bytes)) {
                // Multi-byte UTF-8 class members need codepoint-aware ClassNode (#29024 follow-up).
                $this->abortCompile();

                return [];
            }

            return [[$bytes, $bytes]];
        }
        $this->advance(1);

        return match ($ch) {
            'd' => [['0', '9']],
            'D' => [],
            'w' => [['A', 'Z'], ['a', 'z'], ['0', '9'], ['_', '_']],
            'W' => [],
            's' => [["\t", "\t"], ["\n", "\n"], ["\v", "\v"], ["\f", "\f"], ["\r", "\r"], [' ', ' ']],
            'S' => [],
            default => [[$ch, $ch]],
        };
    }

    private function parseEscape(): VmPregAstNode
    {
        $this->advance(1);
        if ($this->atEnd()) {
            $this->abortCompile(); return new VmPregAstEmptyNode();
        }
        $ch = $this->peek();
        if ($ch >= '0' && $ch <= '7') {
            $octal = '';
            for ($i = 0; $i < 3 && !$this->atEnd(); ++$i) {
                $digit = $this->peek();
                if ($digit < '0' || $digit > '7') {
                    break;
                }
                $octal .= $digit;
                $this->advance(1);
            }

            return new VmPregAstCharNode(\chr(\octdec($octal)), $this->caseless);
        }
        if ('x' === $ch) {
            $this->advance(1);
            $bytes = $this->parseHexEscapeBytes();
            if (null === $bytes) {
                $this->abortCompile();

                return new VmPregAstEmptyNode();
            }

            return new VmPregAstCharNode($bytes, $this->caseless);
        }
        if ('p' === $ch || 'P' === $ch) {
            $this->advance(1);

            return $this->parseUnicodePropertyEscape('P' === $ch);
        }
        $this->advance(1);
        if ($this->utf) {
            // PCRE2_UTF|PCRE2_UCP: \w/\d/\s use Unicode properties (#22003).
            return match ($ch) {
                'd' => new VmPregAstUnicodePropNode('digit', false),
                'D' => new VmPregAstUnicodePropNode('digit', true),
                'w' => new VmPregAstUnicodePropNode('word', false),
                'W' => new VmPregAstUnicodePropNode('word', true),
                's' => new VmPregAstUnicodePropNode('space', false),
                'S' => new VmPregAstUnicodePropNode('space', true),
                'A' => new VmPregAstBolNode(false),
                'K' => new VmPregAstKeepOutNode(),
                'Z' => new VmPregAstEolNode(false, true),
                'z' => new VmPregAstEolNode(false, true),
                default => new VmPregAstCharNode($ch, $this->caseless),
            };
        }

        return match ($ch) {
            'd' => new VmPregAstClassNode([['0', '9']], false, false),
            'D' => new VmPregAstClassNode([['0', '9']], true, false),
            'w' => new VmPregAstClassNode([['A', 'Z'], ['a', 'z'], ['0', '9'], ['_', '_']], false, false),
            'W' => new VmPregAstClassNode([['A', 'Z'], ['a', 'z'], ['0', '9'], ['_', '_']], true, false),
            's' => new VmPregAstClassNode([["\t", "\t"], ["\n", "\n"], ["\v", "\v"], ["\f", "\f"], ["\r", "\r"], [' ', ' ']], false, false),
            'S' => new VmPregAstClassNode([["\t", "\t"], ["\n", "\n"], ["\v", "\v"], ["\f", "\f"], ["\r", "\r"], [' ', ' ']], true, false),
            'A' => new VmPregAstBolNode(false),
            'K' => new VmPregAstKeepOutNode(),
            'Z' => new VmPregAstEolNode(false, true),
            'z' => new VmPregAstEolNode(false, true),
            default => new VmPregAstCharNode($ch, $this->caseless),
        };
    }

    /**
     * Parse PCRE2 `\xHH` / `\x{…}` after the `x` has been consumed (#29024).
     *
     * Brace form: Unicode code point under `/u` (UTF-8 bytes); otherwise a single byte (≤0xFF).
     * Bare form: up to two hex digits (empty → NUL byte, matching PCRE2).
     *
     * @return string|null encoded character bytes, or null on compile failure
     */
    private function parseHexEscapeBytes(): ?string
    {
        if (!$this->atEnd() && '{' === $this->peek()) {
            $this->advance(1);
            $hex = '';
            while (!$this->atEnd() && '}' !== $this->peek()) {
                $digit = $this->peek();
                if (!\ctype_xdigit($digit)) {
                    return null;
                }
                $hex .= $digit;
                $this->advance(1);
            }
            if ($this->atEnd() || '}' !== $this->peek() || '' === $hex) {
                return null;
            }
            $this->advance(1);
            $cp = (int) \hexdec($hex);
            if ($this->utf) {
                return VmPregUtf8::encodeCodepoint($cp);
            }
            if ($cp > 0xFF) {
                return null;
            }

            return \chr($cp);
        }

        $hex = '';
        for ($i = 0; $i < 2 && !$this->atEnd() && \ctype_xdigit($this->peek()); ++$i) {
            $hex .= $this->peek();
            $this->advance(1);
        }
        if ('' === $hex) {
            // PCRE2: `\x` with no digits is U+0000 / NUL (not a compile error).
            return "\0";
        }

        return \chr((int) \hexdec($hex));
    }

    /** Parse `\p{L}` / `\P{L}` / `\pL` Unicode property escapes (PCRE2, #22003). */
    private function parseUnicodePropertyEscape(bool $negated): VmPregAstNode
    {
        if ($this->atEnd()) {
            $this->abortCompile();

            return new VmPregAstEmptyNode();
        }
        $name = '';
        if ('{' === $this->peek()) {
            $this->advance(1);
            $start = $this->pos;
            while (!$this->atEnd() && '}' !== $this->peek()) {
                $this->advance(1);
            }
            if ('}' !== $this->peek()) {
                $this->abortCompile();

                return new VmPregAstEmptyNode();
            }
            $name = \substr($this->regex, $start, $this->pos - $start);
            $this->advance(1);
        } else {
            // Single-letter form \pL
            $name = $this->peek();
            if ('' === $name || !\ctype_alpha($name)) {
                $this->abortCompile();

                return new VmPregAstEmptyNode();
            }
            $this->advance(1);
        }
        $name = \trim($name);
        if ('' === $name) {
            $this->abortCompile();

            return new VmPregAstEmptyNode();
        }

        return new VmPregAstUnicodePropNode($name, $negated);
    }

    private function readLiteralChar(): string
    {
        $ch = $this->peek();
        $this->advance(1);

        return $ch;
    }

    private function skipExtended(): void
    {
        if (!$this->extended) {
            return;
        }
        while (!$this->atEnd()) {
            $ch = $this->peek();
            if (' ' === $ch || "\t" === $ch || "\n" === $ch || "\r" === $ch) {
                $this->advance(1);
                continue;
            }
            if ('#' === $ch) {
                while (!$this->atEnd() && "\n" !== $this->peek()) {
                    $this->advance(1);
                }
                continue;
            }
            break;
        }
    }

    private function peek(): string
    {
        return $this->regex[$this->pos] ?? '';
    }

    private function atEnd(): bool
    {
        return $this->pos >= \strlen($this->regex);
    }

    private function advance(int $n): void
    {
        $this->pos += $n;
    }

    /**
     * PCRE `(?=…)` / `(?!…)` — zero-width lookahead; captures from a successful positive
     * assertion are kept (php-src/PCRE2, #22002).
     *
     * @param array<int, array{0: int, 1: int}> $captures
     */
    public function matchLookahead(
        VmPregAstNode $inner,
        bool $positive,
        string $subject,
        int $pos,
        int $len,
        array &$captures
    ): bool {
        $saved = $captures;
        $ok = $this->matchNode($inner, $subject, $pos, $len, $captures);
        if ($positive) {
            if (!$ok) {
                $captures = $saved;

                return false;
            }
            $captures[0] = [$this->matchStartPos(), $pos];

            return true;
        }
        $captures = $saved;
        if ($ok) {
            return false;
        }
        $captures[0] = [$this->matchStartPos(), $pos];

        return true;
    }

    /**
     * PCRE `(?<=…)` / `(?<!…)` — zero-width lookbehind ending at $pos (#22002).
     *
     * @param array<int, array{0: int, 1: int}> $captures
     */
    public function matchLookbehind(
        VmPregAstNode $inner,
        bool $positive,
        string $subject,
        int $pos,
        int $len,
        array &$captures
    ): bool {
        $saved = $captures;
        $found = false;
        $bestCaps = $saved;
        // Try every start offset that could end at $pos (variable-length lookbehind).
        for ($start = $pos; $start >= 0; --$start) {
            $try = $saved;
            if (!$this->matchNode($inner, $subject, $start, $len, $try)) {
                continue;
            }
            $end = $try[0][1] ?? $start;
            if ($end === $pos) {
                $found = true;
                $bestCaps = $try;
                break;
            }
        }
        if ($positive) {
            if (!$found) {
                $captures = $saved;

                return false;
            }
            $captures = $bestCaps;
            $captures[0] = [$this->matchStartPos(), $pos];

            return true;
        }
        $captures = $saved;
        if ($found) {
            return false;
        }
        $captures[0] = [$this->matchStartPos(), $pos];

        return true;
    }

    /**
     * PCRE atomic grouping (no backtrack into inner alternatives) (#22002).
     *
     * @param array<int, array{0: int, 1: int}> $captures
     */
    public function matchAtomic(
        VmPregAstNode $inner,
        string $subject,
        int $pos,
        int $len,
        array &$captures
    ): bool {
        $saved = $captures;
        if (!$this->matchNode($inner, $subject, $pos, $len, $captures)) {
            $captures = $saved;

            return false;
        }

        return true;
    }

    /**
     * @param array<int, array{0: int, 1: int}> $captures
     */
    public function matchNode(
        VmPregAstNode $node,
        string $subject,
        int $pos,
        int $len,
        array &$captures
    ): bool {
        if ($this->backtrackLimit >= 0 && ++$this->backtrackCount > $this->backtrackLimit) {
            $this->limitErrorCode = StdlibConstants::PREG_BACKTRACK_LIMIT_ERROR;

            return false;
        }

        return $node->match($this, $subject, $pos, $len, $captures);
    }

    /**
     * PCRE `(?R)` / `(?0)` — recurse the whole pattern (ext/pcre/php_pcre.c, #16176).
     *
     * @param array<int, array{0: int, 1: int}> $captures
     */
    public function matchRecursion(string $subject, int $pos, int $len, array &$captures): bool
    {
        if (null === $this->rootAst) {
            return false;
        }
        if (++$this->recursionDepth > $this->jitStackLimit) {
            $this->limitErrorCode = StdlibConstants::PREG_JIT_STACKLIMIT_ERROR;
            --$this->recursionDepth;

            return false;
        }
        $matched = $this->matchNode($this->rootAst, $subject, $pos, $len, $captures);
        --$this->recursionDepth;

        return $matched;
    }

    /**
     * @param array<int, array{0: int, 1: int}> $captures
     */
    public function matchQuant(
        VmPregAstNode $child,
        int $min,
        int $max,
        bool $greedy,
        string $subject,
        int $pos,
        int $len,
        array &$captures
    ): bool {
        if ($greedy) {
            return $this->matchQuantGreedy($child, $min, $max, $subject, $pos, $len, $captures);
        }

        return $this->matchQuantLazy($child, $min, $max, $subject, $pos, $len, $captures);
    }

    /**
     * @param array<int, array{0: int, 1: int}> $captures
     */
    private function matchQuantGreedy(
        VmPregAstNode $child,
        int $min,
        int $max,
        string $subject,
        int $pos,
        int $len,
        array &$captures
    ): bool {
        return $this->matchQuantBacktrack($child, $min, $max, true, $subject, $pos, $len, $captures);
    }

    /**
     * @param array<int, array{0: int, 1: int}> $captures
     */
    private function matchQuantLazy(
        VmPregAstNode $child,
        int $min,
        int $max,
        string $subject,
        int $pos,
        int $len,
        array &$captures
    ): bool {
        return $this->matchQuantBacktrack($child, $min, $max, false, $subject, $pos, $len, $captures);
    }

    /**
     * @param array<int, array{0: int, 1: int}> $captures
     */
    private function matchQuantBacktrack(
        VmPregAstNode $child,
        int $min,
        int $max,
        bool $greedy,
        string $subject,
        int $pos,
        int $len,
        array &$captures
    ): bool {
        $saved = $captures;
        $matches = [];
        $cur = $pos;
        $count = 0;
        while ($count < $max) {
            $sub = $captures;
            if (!$this->matchNode($child, $subject, $cur, $len, $sub)) {
                break;
            }
            $next = $sub[0][1] ?? $cur;
            if ($next <= $cur) {
                break;
            }
            $matches[] = $sub;
            $cur = $next;
            ++$count;
        }
        if ($count < $min) {
            $captures = $saved;

            return false;
        }
        $startTry = $greedy ? $count : $min;
        $endTry = $greedy ? $min : $count;
        for ($try = $startTry; $greedy ? $try >= $endTry : $try <= $endTry; $greedy ? --$try : ++$try) {
            $tryCaptures = $saved;
            $p = $pos;
            $ok = true;
            for ($i = 0; $i < $try; ++$i) {
                if (!$this->matchNode($child, $subject, $p, $len, $tryCaptures)) {
                    $ok = false;
                    break;
                }
                $p = $tryCaptures[0][1] ?? $p;
            }
            if ($ok) {
                $captures = $tryCaptures;
                $captures[0] = [$pos, $p];

                return true;
            }
        }
        $captures = $saved;

        return false;
    }

    /**
     * @param list<VmPregAstNode> $parts
     */
    public function matchConcatParts(
        array $parts,
        int $idx,
        string $subject,
        int &$pos,
        int $len,
        array &$captures
    ): bool {
        if ($idx >= \count($parts)) {
            return true;
        }
        $part = $parts[$idx];
        $quant = self::unwrapQuantPart($part);
        if (null !== $quant) {
            return $this->matchQuantWithContinuation(
                $quant['node'],
                $part,
                $parts,
                $idx,
                $subject,
                $pos,
                $len,
                $captures
            );
        }
        $saved = $captures;
        $cur = $pos;
        if (!$this->matchNode($part, $subject, $cur, $len, $captures)) {
            return false;
        }
        $pos = $captures[0][1] ?? $cur;
        if (!$this->matchConcatParts($parts, $idx + 1, $subject, $pos, $len, $captures)) {
            $captures = $saved;
            $pos = $cur;

            return false;
        }

        return true;
    }

    /**
     * Quantifier + rest of concat — true NFA-style backtracking into nested greedy
     * lengths (php-src/PCRE MATCHLIMIT; #21958 / #12289).
     *
     * Prior path rematched each repetition greedily once per count, so
     * `(?:\D+|<\d+>)*[!?]` never explored partitions of `\D+` and left
     * preg_last_error() at 0 instead of PREG_BACKTRACK_LIMIT_ERROR.
     *
     * @param list<VmPregAstNode> $parts
     * @param array<int, array{0: int, 1: int}> $captures
     */
    private function matchQuantWithContinuation(
        VmPregAstQuantNode $quant,
        VmPregAstNode $wrapperPart,
        array $parts,
        int $idx,
        string $subject,
        int &$pos,
        int $len,
        array &$captures
    ): bool {
        $start = $pos;
        $saved = $captures;
        if ($this->matchQuantContRecurse(
            $quant,
            $wrapperPart,
            $parts,
            $idx,
            $subject,
            $len,
            $saved,
            0,
            $start,
            $start,
            $captures,
            $pos
        )) {
            return true;
        }
        $captures = $saved;
        $pos = $start;

        return false;
    }

    /**
     * @param list<VmPregAstNode> $parts
     * @param array<int, array{0: int, 1: int}> $baseCaptures
     * @param array<int, array{0: int, 1: int}> $captures
     */
    private function matchQuantContRecurse(
        VmPregAstQuantNode $quant,
        VmPregAstNode $wrapperPart,
        array $parts,
        int $idx,
        string $subject,
        int $len,
        array $baseCaptures,
        int $taken,
        int $quantStart,
        int $cur,
        array &$captures,
        int &$pos
    ): bool {
        if (0 !== $this->limitErrorCode) {
            return false;
        }
        if ($this->backtrackLimit >= 0 && ++$this->backtrackCount > $this->backtrackLimit) {
            $this->limitErrorCode = StdlibConstants::PREG_BACKTRACK_LIMIT_ERROR;

            return false;
        }

        $min = $quant->minCount();
        $max = $quant->maxCount();
        $greedy = $quant->isGreedy();
        $capsHere = 0 === $taken ? $baseCaptures : $captures;

        $applyWrapperAndRest = function (int $end, array $caps) use (
            $wrapperPart,
            $parts,
            $idx,
            $subject,
            $len,
            &$captures,
            &$pos,
            $quantStart
        ): bool {
            if ($wrapperPart instanceof VmPregAstGroupNode) {
                $caps[$wrapperPart->groupIndex()] = [$quantStart, $end];
            }
            $caps[0] = [$quantStart, $end];
            $captures = $caps;
            $pos = $end;

            return $this->matchConcatParts($parts, $idx + 1, $subject, $pos, $len, $captures);
        };

        $tryMore = function () use (
            $quant,
            $wrapperPart,
            $parts,
            $idx,
            $subject,
            $len,
            $baseCaptures,
            $taken,
            $quantStart,
            $cur,
            $capsHere,
            &$captures,
            &$pos
        ): bool {
            return $this->matchNodeWithCont(
                $quant->childNode(),
                $subject,
                $cur,
                $len,
                $capsHere,
                function (int $newPos, array $newCaps) use (
                    $quant,
                    $wrapperPart,
                    $parts,
                    $idx,
                    $subject,
                    $len,
                    $baseCaptures,
                    $taken,
                    $quantStart,
                    $cur,
                    &$captures,
                    &$pos
                ): bool {
                    if ($newPos <= $cur) {
                        return false;
                    }
                    $captures = $newCaps;

                    return $this->matchQuantContRecurse(
                        $quant,
                        $wrapperPart,
                        $parts,
                        $idx,
                        $subject,
                        $len,
                        $baseCaptures,
                        $taken + 1,
                        $quantStart,
                        $newPos,
                        $captures,
                        $pos
                    );
                }
            );
        };

        if ($greedy) {
            if ($taken < $max) {
                if ($tryMore()) {
                    return true;
                }
                if (0 !== $this->limitErrorCode) {
                    return false;
                }
            }
            if ($taken >= $min) {
                return $applyWrapperAndRest($cur, $capsHere);
            }

            return false;
        }

        if ($taken >= $min) {
            if ($applyWrapperAndRest($cur, $capsHere)) {
                return true;
            }
            if (0 !== $this->limitErrorCode) {
                return false;
            }
        }
        if ($taken < $max) {
            return $tryMore();
        }

        return false;
    }

    /**
     * Invoke $cont for each way $node can match at $pos (longest-first for greedy quants).
     *
     * @param array<int, array{0: int, 1: int}> $captures
     * @param callable(int, array<int, array{0: int, 1: int}>): bool $cont
     */
    public function matchNodeWithCont(
        VmPregAstNode $node,
        string $subject,
        int $pos,
        int $len,
        array $captures,
        callable $cont
    ): bool {
        if (0 !== $this->limitErrorCode) {
            return false;
        }
        if ($node instanceof VmPregAstQuantNode) {
            return $this->matchQuantNodeWithCont($node, $subject, $pos, $len, $captures, $cont);
        }
        if ($node instanceof VmPregAstAltNode) {
            foreach ($node->branches() as $branch) {
                if ($this->matchNodeWithCont($branch, $subject, $pos, $len, $captures, $cont)) {
                    return true;
                }
                if (0 !== $this->limitErrorCode) {
                    return false;
                }
            }

            return false;
        }
        if ($node instanceof VmPregAstBranchResetAltNode) {
            foreach ($node->branches() as $branch) {
                if ($this->matchNodeWithCont($branch, $subject, $pos, $len, $captures, $cont)) {
                    return true;
                }
                if (0 !== $this->limitErrorCode) {
                    return false;
                }
            }

            return false;
        }
        if ($node instanceof VmPregAstConcatNode) {
            return $this->matchConcatPartsWithCont($node->parts(), 0, $subject, $pos, $len, $captures, $cont);
        }
        if ($node instanceof VmPregAstGroupNode) {
            $start = $pos;

            return $this->matchNodeWithCont(
                $node->innerNode(),
                $subject,
                $pos,
                $len,
                $captures,
                function (int $end, array $caps) use ($node, $start, $cont): bool {
                    $caps[$node->groupIndex()] = [$start, $end];
                    $caps[0] = [$this->matchStartPos(), $end];

                    return $cont($end, $caps);
                }
            );
        }
        if ($node instanceof VmPregAstAtomicNode) {
            // Atomic: commit to the first (greedy) inner match; do not re-enter on cont failure.
            $caps = $captures;
            if (!$this->matchNode($node->innerNode(), $subject, $pos, $len, $caps)) {
                return false;
            }
            $end = $caps[0][1] ?? $pos;

            return $cont($end, $caps);
        }
        if ($node instanceof VmPregAstLookaheadNode) {
            $caps = $captures;
            if (!$this->matchLookahead($node->innerNode(), $node->isPositive(), $subject, $pos, $len, $caps)) {
                return false;
            }

            return $cont($pos, $caps);
        }
        if ($node instanceof VmPregAstLookbehindNode) {
            $caps = $captures;
            if (!$this->matchLookbehind($node->innerNode(), $node->isPositive(), $subject, $pos, $len, $caps)) {
                return false;
            }

            return $cont($pos, $caps);
        }

        $caps = $captures;
        if (!$this->matchNode($node, $subject, $pos, $len, $caps)) {
            return false;
        }
        $end = $caps[0][1] ?? $pos;

        return $cont($end, $caps);
    }

    /**
     * @param array<int, array{0: int, 1: int}> $captures
     * @param callable(int, array<int, array{0: int, 1: int}>): bool $cont
     */
    private function matchQuantNodeWithCont(
        VmPregAstQuantNode $quant,
        string $subject,
        int $pos,
        int $len,
        array $captures,
        callable $cont
    ): bool {
        return $this->matchQuantNodeContRecurseInner(
            $quant,
            $subject,
            $len,
            $captures,
            0,
            $pos,
            $pos,
            $cont
        );
    }

    /**
     * @param array<int, array{0: int, 1: int}> $captures
     * @param callable(int, array<int, array{0: int, 1: int}>): bool $cont
     */
    private function matchQuantNodeContRecurseInner(
        VmPregAstQuantNode $quant,
        string $subject,
        int $len,
        array $captures,
        int $taken,
        int $quantStart,
        int $cur,
        callable $cont
    ): bool {
        if (0 !== $this->limitErrorCode) {
            return false;
        }
        if ($this->backtrackLimit >= 0 && ++$this->backtrackCount > $this->backtrackLimit) {
            $this->limitErrorCode = StdlibConstants::PREG_BACKTRACK_LIMIT_ERROR;

            return false;
        }

        $min = $quant->minCount();
        $max = $quant->maxCount();
        $greedy = $quant->isGreedy();

        $finish = function () use ($cont, $quantStart, $cur, $captures): bool {
            $caps = $captures;
            $caps[0] = [$quantStart, $cur];

            return $cont($cur, $caps);
        };

        $tryMore = function () use (
            $quant,
            $subject,
            $len,
            $captures,
            $taken,
            $quantStart,
            $cur,
            $cont
        ): bool {
            return $this->matchNodeWithCont(
                $quant->childNode(),
                $subject,
                $cur,
                $len,
                $captures,
                function (int $newPos, array $newCaps) use (
                    $quant,
                    $subject,
                    $len,
                    $taken,
                    $quantStart,
                    $cur,
                    $cont
                ): bool {
                    if ($newPos <= $cur) {
                        return false;
                    }

                    return $this->matchQuantNodeContRecurseInner(
                        $quant,
                        $subject,
                        $len,
                        $newCaps,
                        $taken + 1,
                        $quantStart,
                        $newPos,
                        $cont
                    );
                }
            );
        };

        if ($greedy) {
            if ($taken < $max) {
                if ($tryMore()) {
                    return true;
                }
                if (0 !== $this->limitErrorCode) {
                    return false;
                }
            }
            if ($taken >= $min) {
                return $finish();
            }

            return false;
        }

        if ($taken >= $min) {
            if ($finish()) {
                return true;
            }
            if (0 !== $this->limitErrorCode) {
                return false;
            }
        }
        if ($taken < $max) {
            return $tryMore();
        }

        return false;
    }

    /**
     * @param list<VmPregAstNode> $parts
     * @param array<int, array{0: int, 1: int}> $captures
     * @param callable(int, array<int, array{0: int, 1: int}>): bool $cont
     */
    private function matchConcatPartsWithCont(
        array $parts,
        int $idx,
        string $subject,
        int $pos,
        int $len,
        array $captures,
        callable $cont
    ): bool {
        if ($idx >= \count($parts)) {
            return $cont($pos, $captures);
        }
        $part = $parts[$idx];

        return $this->matchNodeWithCont(
            $part,
            $subject,
            $pos,
            $len,
            $captures,
            function (int $newPos, array $newCaps) use ($parts, $idx, $subject, $len, $cont): bool {
                return $this->matchConcatPartsWithCont(
                    $parts,
                    $idx + 1,
                    $subject,
                    $newPos,
                    $len,
                    $newCaps,
                    $cont
                );
            }
        );
    }

    /**
     * @return array{node: VmPregAstQuantNode}|null
     */
    private static function unwrapQuantPart(VmPregAstNode $part): ?array
    {
        if ($part instanceof VmPregAstQuantNode) {
            return ['node' => $part];
        }
        if ($part instanceof VmPregAstGroupNode && $part->innerNode() instanceof VmPregAstQuantNode) {
            return ['node' => $part->innerNode()];
        }

        return null;
    }

    public function charEqual(string $a, string $b): bool
    {
        if ($this->caseless) {
            return \strtolower($a) === \strtolower($b);
        }

        return $a === $b;
    }
}

interface VmPregAstNode
{
    /**
     * @param array<int, array{0: int, 1: int}> $captures
     */
    public function match(
        VmPregEngine $engine,
        string $subject,
        int $pos,
        int $len,
        array &$captures
    ): bool;
}

final class VmPregCompileException extends \Exception
{
    public function __construct(
        public readonly string $compileMessage = 'Internal error',
        public readonly int $compileOffset = 0,
    ) {
        parent::__construct($compileMessage);
    }
}

final class VmPregAstRecursionNode implements VmPregAstNode
{
    public function match(
        VmPregEngine $engine,
        string $subject,
        int $pos,
        int $len,
        array &$captures
    ): bool {
        return $engine->matchRecursion($subject, $pos, $len, $captures);
    }
}

final class VmPregAstEmptyNode implements VmPregAstNode
{
    public function match(
        VmPregEngine $engine,
        string $subject,
        int $pos,
        int $len,
        array &$captures
    ): bool {
        unset($engine, $subject, $len);
        $captures[0] = [$pos, $pos];

        return true;
    }
}

final class VmPregAstCharNode implements VmPregAstNode
{
    public function __construct(
        private readonly string $char,
        private readonly bool $caseless
    ) {
    }

    public function match(
        VmPregEngine $engine,
        string $subject,
        int $pos,
        int $len,
        array &$captures
    ): bool {
        unset($engine);
        $want = $this->char;
        $wantLen = \strlen($want);
        if ($wantLen < 1 || $pos + $wantLen > $len) {
            return false;
        }
        $sub = $wantLen === 1 ? $subject[$pos] : \substr($subject, $pos, $wantLen);
        if ($this->caseless && 1 === $wantLen) {
            // ASCII caseless only for single-byte literals (PCRE2 Unicode caseless is separate).
            if (\strtolower($sub) !== \strtolower($want)) {
                return false;
            }
        } elseif ($sub !== $want) {
            return false;
        }
        $captures[0] = [$pos, $pos + $wantLen];

        return true;
    }
}

final class VmPregAstAnyNode implements VmPregAstNode
{
    public function __construct(
        private readonly bool $dotall,
        private readonly bool $utf = false
    ) {
    }

    public function match(
        VmPregEngine $engine,
        string $subject,
        int $pos,
        int $len,
        array &$captures
    ): bool {
        unset($engine);
        if ($pos >= $len) {
            return false;
        }
        if (!$this->dotall && "\n" === $subject[$pos]) {
            return false;
        }
        if ($this->utf) {
            $decoded = VmPregUtf8::codepointAt($subject, $pos, $len);
            if (null === $decoded) {
                return false;
            }
            $width = $decoded[1];
            $captures[0] = [$pos, $pos + $width];

            return true;
        }
        $captures[0] = [$pos, $pos + 1];

        return true;
    }
}

final class VmPregAstBolNode implements VmPregAstNode
{
    public function __construct(private readonly bool $multiline)
    {
    }

    public function match(
        VmPregEngine $engine,
        string $subject,
        int $pos,
        int $len,
        array &$captures
    ): bool {
        unset($engine, $len);
        $ok = 0 === $pos || ($this->multiline && "\n" === $subject[$pos - 1]);
        if (!$ok) {
            return false;
        }
        $captures[0] = [$pos, $pos];

        return true;
    }
}

final class VmPregAstEolNode implements VmPregAstNode
{
    public function __construct(
        private readonly bool $multiline,
        private readonly bool $endonly
    ) {
    }

    public function match(
        VmPregEngine $engine,
        string $subject,
        int $pos,
        int $len,
        array &$captures
    ): bool {
        unset($engine);
        $ok = $pos === $len || ($this->multiline && $pos < $len && "\n" === $subject[$pos]);
        if ($this->endonly && $pos !== $len) {
            $ok = false;
        }
        if (!$ok) {
            return false;
        }
        $captures[0] = [$pos, $pos];

        return true;
    }
}

/** PCRE \\K keep-out — reset reported match start without dropping prior captures (ext/pcre/php_pcre.c). */
final class VmPregAstKeepOutNode implements VmPregAstNode
{
    public function match(
        VmPregEngine $engine,
        string $subject,
        int $pos,
        int $len,
        array &$captures
    ): bool {
        unset($subject, $len);
        $engine->keepOutAt($pos);
        $end = $captures[0][1] ?? $pos;
        $captures[0] = [$pos, $end];

        return true;
    }
}

final class VmPregAstConcatNode implements VmPregAstNode
{
    /** @param list<VmPregAstNode> $parts */
    public function __construct(private readonly array $parts)
    {
    }

    /** @return list<VmPregAstNode> */
    public function parts(): array
    {
        return $this->parts;
    }

    public function match(
        VmPregEngine $engine,
        string $subject,
        int $pos,
        int $len,
        array &$captures
    ): bool {
        $start = $pos;
        $saved = $captures;
        if (!$engine->matchConcatParts($this->parts, 0, $subject, $pos, $len, $captures)) {
            $captures = $saved;

            return false;
        }
        $captures[0] = [$engine->matchStartPos(), $pos];

        return true;
    }
}

final class VmPregAstAltNode implements VmPregAstNode
{
    /** @param list<VmPregAstNode> $branches */
    public function __construct(private readonly array $branches)
    {
    }

    /** @return list<VmPregAstNode> */
    public function branches(): array
    {
        return $this->branches;
    }

    public function match(
        VmPregEngine $engine,
        string $subject,
        int $pos,
        int $len,
        array &$captures
    ): bool {
        foreach ($this->branches as $branch) {
            $sub = $captures;
            if ($engine->matchNode($branch, $subject, $pos, $len, $sub)) {
                $captures = $sub;

                return true;
            }
        }

        return false;
    }
}

/** `(?|alt|alt)` — each alternative reuses capture numbering from the same base (#14091). */
final class VmPregAstBranchResetAltNode implements VmPregAstNode
{
    /** @param list<VmPregAstNode> $branches */
    public function __construct(private readonly array $branches)
    {
    }

    /** @return list<VmPregAstNode> */
    public function branches(): array
    {
        return $this->branches;
    }

    public function match(
        VmPregEngine $engine,
        string $subject,
        int $pos,
        int $len,
        array &$captures
    ): bool {
        foreach ($this->branches as $branch) {
            $sub = $captures;
            if ($engine->matchNode($branch, $subject, $pos, $len, $sub)) {
                $captures = $sub;

                return true;
            }
        }

        return false;
    }
}

final class VmPregAstQuantNode implements VmPregAstNode
{
    public function __construct(
        private readonly VmPregAstNode $child,
        private readonly int $min,
        private readonly int $max,
        private readonly bool $greedy
    ) {
    }

    public function childNode(): VmPregAstNode
    {
        return $this->child;
    }

    public function minCount(): int
    {
        return $this->min;
    }

    public function maxCount(): int
    {
        return $this->max;
    }

    public function isGreedy(): bool
    {
        return $this->greedy;
    }

    public function match(
        VmPregEngine $engine,
        string $subject,
        int $pos,
        int $len,
        array &$captures
    ): bool {
        return $engine->matchQuant(
            $this->child,
            $this->min,
            $this->max,
            $this->greedy,
            $subject,
            $pos,
            $len,
            $captures
        );
    }
}

final class VmPregAstGroupNode implements VmPregAstNode
{
    public function __construct(
        private readonly VmPregAstNode $inner,
        private readonly int $index
    ) {
    }

    public function innerNode(): VmPregAstNode
    {
        return $this->inner;
    }

    public function groupIndex(): int
    {
        return $this->index;
    }

    public function match(
        VmPregEngine $engine,
        string $subject,
        int $pos,
        int $len,
        array &$captures
    ): bool {
        $saved = $captures;
        $start = $pos;
        if (!$engine->matchNode($this->inner, $subject, $pos, $len, $captures)) {
            $captures = $saved;

            return false;
        }
        $end = $captures[0][1] ?? $pos;
        $captures[$this->index] = [$start, $end];
        $captures[0] = [$engine->matchStartPos(), $end];

        return true;
    }
}

/** PCRE `(?=…)` / `(?!…)` zero-width lookahead (#22002). */
final class VmPregAstLookaheadNode implements VmPregAstNode
{
    public function __construct(
        private readonly VmPregAstNode $inner,
        private readonly bool $positive
    ) {
    }

    public function innerNode(): VmPregAstNode
    {
        return $this->inner;
    }

    public function isPositive(): bool
    {
        return $this->positive;
    }

    public function match(
        VmPregEngine $engine,
        string $subject,
        int $pos,
        int $len,
        array &$captures
    ): bool {
        return $engine->matchLookahead($this->inner, $this->positive, $subject, $pos, $len, $captures);
    }
}

/** PCRE `(?<=…)` / `(?<!…)` zero-width lookbehind (#22002). */
final class VmPregAstLookbehindNode implements VmPregAstNode
{
    public function __construct(
        private readonly VmPregAstNode $inner,
        private readonly bool $positive
    ) {
    }

    public function innerNode(): VmPregAstNode
    {
        return $this->inner;
    }

    public function isPositive(): bool
    {
        return $this->positive;
    }

    public function match(
        VmPregEngine $engine,
        string $subject,
        int $pos,
        int $len,
        array &$captures
    ): bool {
        return $engine->matchLookbehind($this->inner, $this->positive, $subject, $pos, $len, $captures);
    }
}

/** PCRE atomic grouping — no backtrack into inner (#22002). */
final class VmPregAstAtomicNode implements VmPregAstNode
{
    public function __construct(private readonly VmPregAstNode $inner)
    {
    }

    public function innerNode(): VmPregAstNode
    {
        return $this->inner;
    }

    public function match(
        VmPregEngine $engine,
        string $subject,
        int $pos,
        int $len,
        array &$captures
    ): bool {
        return $engine->matchAtomic($this->inner, $subject, $pos, $len, $captures);
    }
}

/** PCRE `\p{…}` / `\P{…}` / UCP `\w` — one UTF-8 code point (#22003). */
final class VmPregAstUnicodePropNode implements VmPregAstNode
{
    public function __construct(
        private readonly string $kind,
        private readonly bool $negated
    ) {
    }

    public function match(
        VmPregEngine $engine,
        string $subject,
        int $pos,
        int $len,
        array &$captures
    ): bool {
        unset($engine);
        $decoded = VmPregUtf8::codepointAt($subject, $pos, $len);
        if (null === $decoded) {
            return false;
        }
        [$cp, $width] = $decoded;
        if (!VmPregUtf8::codepointMatchesProp($cp, $this->kind, $this->negated)) {
            return false;
        }
        $captures[0] = [$pos, $pos + $width];

        return true;
    }
}

final class VmPregAstClassNode implements VmPregAstNode
{
    /**
     * @param list<array{0: string, 1: string}> $ranges
     */
    public function __construct(
        private readonly array $ranges,
        private readonly bool $negated,
        private readonly bool $caseless
    ) {
    }

    public function match(
        VmPregEngine $engine,
        string $subject,
        int $pos,
        int $len,
        array &$captures
    ): bool {
        unset($engine);
        if ($pos >= $len) {
            return false;
        }
        $ch = $subject[$pos];
        $in = false;
        foreach ($this->ranges as [$from, $to]) {
            if ($this->caseless) {
                $c = \strtolower($ch);
                $f = \strtolower($from);
                $t = \strtolower($to);
            } else {
                $c = $ch;
                $f = $from;
                $t = $to;
            }
            if ($c >= $f && $c <= $t) {
                $in = true;
                break;
            }
        }
        if ($this->negated) {
            $in = !$in;
        }
        if (!$in) {
            return false;
        }
        $captures[0] = [$pos, $pos + 1];

        return true;
    }
}
