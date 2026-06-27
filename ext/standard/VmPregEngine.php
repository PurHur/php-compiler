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
    private const OPT_CASELESS = 0x00000008;

    private const OPT_DOTALL = 0x00000020;

    private const OPT_DOLLAR_ENDONLY = 0x00000010;

    private const OPT_EXTENDED = 0x00000080;

    private const OPT_MULTILINE = 0x00000400;

    private const OPT_UNGREEDY = 0x00040000;

    private const OPT_UTF = 0x00080000;

    private const OPT_ANCHORED = 0x80000000;

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

    private int $nextGroup = 1;

    private string $regex = '';

    private int $pos = 0;

    private int $backtrackCount = 0;

    private int $backtrackLimit = 0;

    /**
     * @return array{0: VmPregAstNode, 1: array<string, int>}|null
     */
    public static function compile(string $regex, int $opts): ?array
    {
        $engine = new self();
        $engine->applyOptions($opts);
        try {
            $ast = $engine->parsePattern($regex);
            if (null === $ast) {
                return null;
            }

            return [$ast, $engine->groupNameToIndex];
        } catch (VmPregCompileException) {
            return null;
        }
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
    ): ?array {
        $engine = new self();
        $engine->applyOptions($opts);
        $engine->groupNameToIndex = $groupNameToIndex;
        $engine->backtrackLimit = VmPregLimits::backtrackLimit();
        $len = \strlen($subject);
        if ($offset < 0 || $offset > $len) {
            return null;
        }
        if ($engine->anchored || $anchoredAttempt) {
            $captures = [];
            if ($engine->matchNode($ast, $subject, $offset, $len, $captures) && $captures[0][1] >= $offset) {
                return self::capturesToOvector($captures);
            }

            return null;
        }
        for ($start = $offset; $start <= $len; ++$start) {
            $captures = [];
            if ($engine->matchNode($ast, $subject, $start, $len, $captures) && $captures[0][0] === $start) {
                return self::capturesToOvector($captures);
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
        foreach (\array_keys($captures) as $k) {
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
        if (!$this->atEnd()) {
            throw new VmPregCompileException();
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
            throw new VmPregCompileException();
        }
        $verb = \substr($this->regex, $this->pos + 2, $close - $this->pos - 2);
        if (!self::isRecognizedPcreVerb($verb)) {
            throw new VmPregCompileException();
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
            default => throw new VmPregCompileException(),
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
                throw new VmPregCompileException();
            }
            $this->advance(1);
            if ($this->peek() === '?') {
                $greedy = !$greedy;
                $this->advance(1);
            }

            return [$min, $max, $greedy];
        }

        throw new VmPregCompileException();
    }

    private function parseDigits(): int
    {
        if (!\ctype_digit($this->peek())) {
            throw new VmPregCompileException();
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
            throw new VmPregCompileException();
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

            return new VmPregAstAnyNode($this->dotall);
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
            throw new VmPregCompileException();
        }
        $lit = $this->readLiteralChar();

        return new VmPregAstCharNode($lit, $this->caseless);
    }

    private function parseGroup(): VmPregAstNode
    {
        $this->advance(1);
        $capture = true;
        $name = null;
        if ($this->peek() === '?') {
            $this->advance(1);
            $flag = $this->peek();
            if (':' === $flag) {
                $capture = false;
                $this->advance(1);
            } elseif ('#' === $flag) {
                $this->advance(1);
                while (!$this->atEnd() && ')' !== $this->peek()) {
                    $this->advance(1);
                }
                if ($this->peek() !== ')') {
                    throw new VmPregCompileException();
                }
                $this->advance(1);

                return new VmPregAstEmptyNode();
            } elseif ('P' === $flag || '<' === $flag) {
                $this->advance(1);
                if ('P' === $flag) {
                    if ($this->peek() !== '<') {
                        throw new VmPregCompileException();
                    }
                    $this->advance(1);
                }
                $name = $this->parseGroupName();
                if ($this->peek() !== '>') {
                    throw new VmPregCompileException();
                }
                $this->advance(1);
            } elseif ($this->isInlineModifierStart($flag)) {
                $this->parseInlineModifiers();
                if ($this->peek() !== ')') {
                    throw new VmPregCompileException();
                }
                $this->advance(1);

                return new VmPregAstEmptyNode();
            } else {
                throw new VmPregCompileException();
            }
        }
        $inner = $this->parseAlternation();
        if ($this->peek() !== ')') {
            throw new VmPregCompileException();
        }
        $this->advance(1);
        if (!$capture) {
            return $inner;
        }
        $index = $this->nextGroup++;
        if (null !== $name) {
            $this->groupNameToIndex[$name] = $index;
        }

        return new VmPregAstGroupNode($inner, $index);
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
                    throw new VmPregCompileException();
                }
                while (!$this->atEnd() && $this->isInlineModifierLetter($this->peek())) {
                    $this->applyInlineModifier($this->peek(), $enable);
                    $this->advance(1);
                }
                continue;
            }
            if (!$this->isInlineModifierLetter($ch)) {
                throw new VmPregCompileException();
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
            'J' => null, // DUPLICATE_GROUP — accepted, no VM engine effect (#12432)
            'u' => $this->utf = $enable,
            'D' => $this->dollarEndonly = $enable,
            'A' => $this->anchored = $enable,
            default => throw new VmPregCompileException(),
        };
    }

    private function parseGroupName(): string
    {
        $ch = $this->peek();
        if ('' === $ch || !self::isNameStart($ch)) {
            throw new VmPregCompileException();
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
            throw new VmPregCompileException();
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
            throw new VmPregCompileException();
        }
        $ch = $this->peek();
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
            throw new VmPregCompileException();
        }
        $ch = $this->peek();
        $this->advance(1);

        return match ($ch) {
            'd' => new VmPregAstClassNode([['0', '9']], false, false),
            'D' => new VmPregAstClassNode([['0', '9']], true, false),
            'w' => new VmPregAstClassNode([['A', 'Z'], ['a', 'z'], ['0', '9'], ['_', '_']], false, false),
            'W' => new VmPregAstClassNode([['A', 'Z'], ['a', 'z'], ['0', '9'], ['_', '_']], true, false),
            's' => new VmPregAstClassNode([["\t", "\t"], ["\n", "\n"], ["\v", "\v"], ["\f", "\f"], ["\r", "\r"], [' ', ' ']], false, false),
            'S' => new VmPregAstClassNode([["\t", "\t"], ["\n", "\n"], ["\v", "\v"], ["\f", "\f"], ["\r", "\r"], [' ', ' ']], true, false),
            'A' => new VmPregAstBolNode(false),
            'Z' => new VmPregAstEolNode(false, true),
            'z' => new VmPregAstEolNode(false, true),
            default => new VmPregAstCharNode($ch, $this->caseless),
        };
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
            throw new VmPregBacktrackLimitException();
        }

        return $node->match($this, $subject, $pos, $len, $captures);
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
        if ($pos >= $len) {
            return false;
        }
        $sub = $subject[$pos];
        $want = $this->char;
        if ($this->caseless) {
            if (\strtolower($sub) !== \strtolower($want)) {
                return false;
            }
        } elseif ($sub !== $want) {
            return false;
        }
        $captures[0] = [$pos, $pos + 1];

        return true;
    }
}

final class VmPregAstAnyNode implements VmPregAstNode
{
    public function __construct(private readonly bool $dotall)
    {
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

final class VmPregAstConcatNode implements VmPregAstNode
{
    /** @param list<VmPregAstNode> $parts */
    public function __construct(private readonly array $parts)
    {
    }

    public function match(
        VmPregEngine $engine,
        string $subject,
        int $pos,
        int $len,
        array &$captures
    ): bool {
        $cur = $pos;
        $saved = $captures;
        foreach ($this->parts as $part) {
            if (!$engine->matchNode($part, $subject, $cur, $len, $captures)) {
                $captures = $saved;

                return false;
            }
            $cur = $captures[0][1] ?? $cur;
        }
        $captures[0] = [$pos, $cur];

        return true;
    }
}

final class VmPregAstAltNode implements VmPregAstNode
{
    /** @param list<VmPregAstNode> $branches */
    public function __construct(private readonly array $branches)
    {
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
        $captures[0] = [$start, $end];

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
