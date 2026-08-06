<?php

declare(strict_types=1);

namespace PHPCompiler\ext\standard;

/**
 * Pure-PHP preg_* for VM bootstrap — no libpcre2 FFI (#8935, #4874, #1492).
 *
 * SSOT: {@see VmPregEngine} + {@see VmPregPattern}
 * php-src: ext/pcre/php_pcre.c
 */
final class VmPregPure
{
    private static int $lastError = 0;

    public static function lastError(): int
    {
        return self::$lastError;
    }

    public static function setLastError(int $code): void
    {
        self::$lastError = $code;
    }

    public static function pregMatch(
        string $pattern,
        string $subject,
        ?array &$matches = null,
        int $flags = 0,
        int $offset = 0
    ): int|false {
        $parsed = VmPregPattern::parsePhpPattern($pattern);
        if (null === $parsed) {
            self::$lastError = 1;

            return false;
        }
        [$regex, $opts] = $parsed;
        $normalizedOffset = self::normalizeMatchSubjectOffset($offset, \strlen($subject));
        if (false === $normalizedOffset) {
            // php-src php_pcre_pce_execute — still initializes $matches to [] (#25313).
            self::$lastError = StdlibConstants::PREG_INTERNAL_ERROR;
            $matches = [];

            return false;
        }
        if (!self::ensureUtf8SubjectForOpts($subject, $opts, $normalizedOffset)) {
            return false;
        }
        $compiled = self::compile($pattern);
        if (null === $compiled) {
            return false;
        }

        $ovector = self::engineMatch(
            $compiled['ast'],
            $compiled['groupNameToIndex'],
            $subject,
            $normalizedOffset,
            $compiled['opts'],
            self::fixedStartMatch($regex, $compiled['opts'])
        );
        if (false === $ovector) {
            return false;
        }
        if (null === $ovector) {
            self::$lastError = 0;
            $matches = [];

            return 0;
        }

        self::$lastError = 0;
        $matches = self::ovectorToMatches(
            $ovector,
            $subject,
            $compiled['groupNameToIndex'],
            $regex,
            0 !== ($flags & StdlibConstants::PREG_OFFSET_CAPTURE),
            0 !== ($flags & StdlibConstants::PREG_UNMATCHED_AS_NULL),
            $compiled['captureGroupCount']
        );

        return 1;
    }

    public static function pregMatchAll(
        string $pattern,
        string $subject,
        ?array &$matches = null,
        int $flags = 0,
        int $offset = 0
    ): int|false {
        $parsed = VmPregPattern::parsePhpPattern($pattern);
        if (null === $parsed) {
            self::$lastError = 1;

            return false;
        }
        [$regex, $opts] = $parsed;
        $subjectLen = \strlen($subject);
        $normalizedOffset = self::normalizeMatchSubjectOffset($offset, $subjectLen);
        if (false === $normalizedOffset) {
            // php-src php_pcre_pce_execute — still initializes $matches to [] (#25313).
            self::$lastError = StdlibConstants::PREG_INTERNAL_ERROR;
            $matches = [];

            return false;
        }
        if (!self::ensureUtf8SubjectForOpts($subject, $opts, $normalizedOffset)) {
            return false;
        }
        $compiled = self::compile($pattern);
        if (null === $compiled) {
            return false;
        }

        $start = $normalizedOffset;
        $count = 0;
        $setOrder = 0 !== ($flags & StdlibConstants::PREG_SET_ORDER);
        $offsetCapture = 0 !== ($flags & StdlibConstants::PREG_OFFSET_CAPTURE);
        $allMatches = [];
        $fixedStart = self::fixedStartMatch($regex, $compiled['opts']);

        while ($start <= $subjectLen) {
            $ovector = self::engineMatch(
                $compiled['ast'],
                $compiled['groupNameToIndex'],
                $subject,
                $start,
                $compiled['opts'],
                $fixedStart && $start === $normalizedOffset
            );
            if (false === $ovector) {
                return false;
            }
            if (null === $ovector) {
                break;
            }
            ++$count;
            $one = self::ovectorToMatches($ovector, $subject, $compiled['groupNameToIndex'], $regex, $offsetCapture, false);
            if ($setOrder) {
                $allMatches[] = $one;
            } else {
                foreach ($one as $key => $val) {
                    $allMatches[$key][] = $val;
                }
            }
            $end = $ovector[1] ?? $start;
            $start = $end === ($ovector[0] ?? $start) ? $end + 1 : $end;
            if ($start > $subjectLen) {
                break;
            }
            $fixedStart = false;
        }

        $matches = $allMatches;
        self::$lastError = 0;

        return $count;
    }

    /**
     * @param string|list<string> $subject
     *
     * @return string|list<string>|null|false
     */
    public static function pregReplace(
        string $pattern,
        string $replacement,
        string|array $subject,
        int $limit = -1,
        ?int &$count = null
    ): string|array|null|false {
        if (\is_array($subject)) {
            $out = [];
            $totalCount = 0;
            foreach ($subject as $key => $item) {
                // php-src convert_to_string on array subject values (#27164).
                if (!\is_string($item)) {
                    $item = (string) $item;
                }
                $elemCount = 0;
                $replaced = self::pregReplaceString($pattern, $replacement, $item, $limit, $elemCount);
                if (false === $replaced) {
                    return false;
                }
                if (null === $replaced) {
                    if (StdlibConstants::PREG_BAD_UTF8_ERROR === self::$lastError) {
                        if (null !== $count) {
                            $count = $totalCount;
                        }

                        return $out;
                    }

                    return null;
                }
                $out[$key] = $replaced;
                $totalCount += $elemCount;
            }
            if (null !== $count) {
                $count = $totalCount;
            }

            return $out;
        }

        return self::pregReplaceString($pattern, $replacement, $subject, $limit, $count);
    }

    /**
     * @return list<string>|list<array{0: string, 1: int}>|false
     */
    public static function pregSplit(string $pattern, string $subject, int $limit = -1, int $flags = 0): array|false
    {
        $parsed = VmPregPattern::parsePhpPattern($pattern);
        if (null === $parsed) {
            self::$lastError = 1;

            return false;
        }
        [$regex, $opts] = $parsed;
        if ('' === $regex) {
            if (!self::ensureUtf8SubjectForOpts($subject, $opts, 0)) {
                return false;
            }

            return self::pregSplitEmptyPattern(
                $subject,
                $limit,
                $flags,
                0 !== ($opts & VmPregPattern::PCRE2_UTF)
            );
        }

        if (!self::ensureUtf8SubjectForOpts($subject, $opts, 0)) {
            return false;
        }
        $compiled = self::compile($pattern);
        if (null === $compiled) {
            return false;
        }

        $offsetCapture = 0 !== ($flags & StdlibConstants::PREG_SPLIT_OFFSET_CAPTURE);
        if (1 === $limit) {
            self::$lastError = 0;

            return [$offsetCapture ? [$subject, 0] : $subject];
        }

        $parts = [];
        $count = 0;
        $maxParts = $limit <= 0 ? \PHP_INT_MAX : $limit;
        $noEmpty = 0 !== ($flags & StdlibConstants::PREG_SPLIT_NO_EMPTY);
        $delimCapture = 0 !== ($flags & StdlibConstants::PREG_SPLIT_DELIM_CAPTURE);
        $subjectLen = \strlen($subject);
        $fixedStart = self::fixedStartMatch($regex, $compiled['opts']);
        $searchOffset = 0;
        $lastMatchOffset = 0;

        while (true) {
            $ovector = self::engineMatch(
                $compiled['ast'],
                $compiled['groupNameToIndex'],
                $subject,
                $searchOffset,
                $compiled['opts'],
                $fixedStart && 0 === $searchOffset
            );
            if (false === $ovector) {
                return false;
            }
            if (null === $ovector) {
                break;
            }
            $matchStart = $ovector[0] ?? $searchOffset;
            $matchEnd = $ovector[1] ?? $searchOffset;
            if (!$noEmpty || $matchStart !== $lastMatchOffset) {
                $chunk = \substr($subject, $lastMatchOffset, $matchStart - $lastMatchOffset);
                if (!$noEmpty || '' !== $chunk) {
                    $parts[] = $offsetCapture ? [$chunk, $lastMatchOffset] : $chunk;
                    ++$count;
                    if ($count >= $maxParts - 1) {
                        $lastMatchOffset = $matchEnd;
                        break;
                    }
                }
            }
            if ($delimCapture) {
                // php-src: PREG_SPLIT_DELIM_CAPTURE only emits capturing subgroups (gi>=1), never
                // the full match — patterns without () are a no-op for this flag (#27946).
                $groupCount = (int) (\count($ovector) / 2);
                for ($gi = 1; $gi < $groupCount; ++$gi) {
                    $gStart = $ovector[$gi * 2] ?? -1;
                    $gEnd = $ovector[$gi * 2 + 1] ?? -1;
                    if ($gStart < 0 || $gEnd < 0) {
                        continue;
                    }
                    $delim = \substr($subject, $gStart, $gEnd - $gStart);
                    if (!$noEmpty || '' !== $delim) {
                        $parts[] = $offsetCapture ? [$delim, $gStart] : $delim;
                        ++$count;
                        if ($count >= $maxParts - 1) {
                            $lastMatchOffset = $matchEnd;
                            break 2;
                        }
                    }
                }
            }
            $searchOffset = $lastMatchOffset = $matchEnd;
            if ($searchOffset === $matchStart) {
                if ($count >= $maxParts - 1) {
                    break;
                }
                if ($searchOffset < $subjectLen) {
                    $searchOffset += self::splitAdvanceUnit($subject, $searchOffset, $compiled['opts']);
                } else {
                    break;
                }
            }
            $fixedStart = false;
        }

        $tailStart = $lastMatchOffset;
        if (!$noEmpty || $tailStart < $subjectLen) {
            $tail = \substr($subject, $tailStart);
            if ($count < $maxParts && (!$noEmpty || '' !== $tail)) {
                $parts[] = $offsetCapture ? [$tail, $tailStart] : $tail;
            }
        }

        self::$lastError = 0;

        return $parts;
    }

    /** php-src ext/pcre/php_pcre.c — calculate_unit_length() for zero-width split advance. */
    private static function splitAdvanceUnit(string $subject, int $byteOffset, int $opts): int
    {
        if ($byteOffset >= \strlen($subject)) {
            return 0;
        }
        if (0 !== ($opts & VmPregPattern::PCRE2_UTF)) {
            $charIndex = 0;
            $pos = 0;
            $charLen = VmPregUtf8::utf8CharLength($subject);
            while ($pos < $byteOffset && $charIndex < $charLen) {
                $pos += \strlen(VmPregUtf8::utf8CharSubstr($subject, $charIndex, 1));
                ++$charIndex;
            }
            $ch = VmPregUtf8::utf8CharSubstr($subject, $charIndex, 1);

            return max(1, \strlen($ch));
        }

        return 1;
    }

    /**
     * @param string|list<string> $subject
     *
     * @return string|list<string>|false|null
     */
    public static function pregFilter(
        string $pattern,
        string $replacement,
        string|array $subject,
        int $limit = -1,
        ?int &$count = null
    ): string|array|null|false {
        $totalCount = 0;
        if (\is_array($subject)) {
            $out = [];
            foreach ($subject as $key => $item) {
                // php-src convert_to_string on array subject values (#27164).
                if (!\is_string($item)) {
                    $item = (string) $item;
                }
                if (1 === self::pregMatch($pattern, $item)) {
                    $itemCount = 0;
                    $replaced = self::pregReplaceString($pattern, $replacement, $item, $limit, $itemCount);
                    if (false === $replaced || null === $replaced) {
                        return $replaced;
                    }
                    // Skip when limit left count at 0 (php-src filter; #21655).
                    if (0 === $itemCount) {
                        continue;
                    }
                    $totalCount += $itemCount;
                    $out[$key] = $replaced;
                }
            }
            if (null !== $count) {
                $count = $totalCount;
            }

            return [] === $out ? null : $out;
        }

        if (1 !== self::pregMatch($pattern, $subject)) {
            if (null !== $count) {
                $count = 0;
            }

            return null;
        }

        $localCount = 0;
        $result = self::pregReplaceString($pattern, $replacement, $subject, $limit, $localCount);
        if (null !== $count) {
            $count = $localCount;
        }
        if (false === $result || null === $result) {
            return $result;
        }
        // php-src php_pcre.c: string subject with 0 replacements → null (limit 0; #21655).
        if (0 === $localCount) {
            return null;
        }

        return $result;
    }

    public static function patternWarningMessage(string $pattern): ?string
    {
        return VmPregCompileWarn::compileWarningMessage($pattern);
    }

    private static function pregReplaceString(
        string $pattern,
        string $replacement,
        string $subject,
        int $limit,
        ?int &$count = null
    ): string|null|false {
        $emptyParsed = PregEmptyPatternReplace::parseEmptyPattern($pattern);
        if (null !== $emptyParsed) {
            [, $opts] = $emptyParsed;
            if (!self::ensureUtf8SubjectForOpts($subject, $opts, 0)) {
                return null;
            }
            self::$lastError = 0;
            $replacements = 0;
            $result = PregEmptyPatternReplace::replace(
                $replacement,
                $subject,
                $limit,
                $opts,
                $replacements
            );
            if (null !== $count) {
                $count = $replacements;
            }

            return $result;
        }

        $parsed = VmPregPattern::parsePhpPattern($pattern);
        if (null === $parsed) {
            self::$lastError = 1;

            return null;
        }
        [$regex, $opts] = $parsed;
        if (!self::ensureUtf8SubjectForOpts($subject, $opts, 0)) {
            return null;
        }
        $compiled = self::compile($pattern);
        if (null === $compiled) {
            self::$lastError = 1;

            return null;
        }

        $out = '';
        $offset = 0;
        $replacements = 0;
        $max = $limit < 0 ? \PHP_INT_MAX : $limit;
        $subjectLen = \strlen($subject);
        $fixedStart = self::fixedStartMatch($regex, $compiled['opts']);

        while ($replacements < $max && $offset <= $subjectLen) {
            $ovector = self::engineMatch(
                $compiled['ast'],
                $compiled['groupNameToIndex'],
                $subject,
                $offset,
                $compiled['opts'],
                $fixedStart && $offset === 0
            );
            if (false === $ovector) {
                return false;
            }
            if (null === $ovector) {
                break;
            }
            $start = $ovector[0] ?? $offset;
            $end = $ovector[1] ?? $offset;
            $out .= \substr($subject, $offset, $start - $offset);
            $out .= PregReplacementExpand::expand(
                $replacement,
                $ovector,
                (int) (\count($ovector) / 2),
                $subject
            );
            $offset = $end;
            ++$replacements;
            if ($end === $start) {
                $offset = $end + 1;
            }
            if ($offset > $subjectLen) {
                break;
            }
            $fixedStart = false;
        }

        if ($offset < $subjectLen) {
            $out .= \substr($subject, $offset);
        }

        self::$lastError = 0;
        if (null !== $count) {
            $count = $replacements;
        }

        return $out;
    }

    /**
     * @return array{ast: VmPregAstNode, groupNameToIndex: array<string, int>, captureGroupCount: int, regex: string, opts: int}|null
     */
    private static function compile(string $pattern): ?array
    {
        $parsed = VmPregPattern::parsePhpPattern($pattern);
        if (null === $parsed) {
            self::$lastError = 1;

            return null;
        }
        [$regex, $opts] = $parsed;
        $engineCompiled = VmPregEngine::compile($regex, $opts);
        if (null === $engineCompiled) {
            self::$lastError = 1;

            return null;
        }
        [$ast, $groupNameToIndex, $captureGroupCount] = $engineCompiled;

        return [
            'ast' => $ast,
            'groupNameToIndex' => $groupNameToIndex,
            'captureGroupCount' => $captureGroupCount,
            'regex' => $regex,
            'opts' => $opts,
        ];
    }

    private static function fixedStartMatch(string $regex, int $opts): bool
    {
        if (0 !== ($opts & 0x80000000)) {
            return true;
        }

        return str_starts_with($regex, '^');
    }

    /**
     * php-src ext/pcre/php_pcre.c — negative $offset counts from end of subject.
     *
     * @return int|false normalized byte offset, or false when offset > subject length
     */
    private static function normalizeMatchSubjectOffset(int $offset, int $subjectLen): int|false
    {
        if ($offset < 0) {
            if (-$offset <= $subjectLen) {
                $offset = $subjectLen + $offset;
            } else {
                $offset = 0;
            }
        }
        if ($offset > $subjectLen) {
            return false;
        }

        return $offset;
    }

    /**
     * php-src ext/pcre/php_pcre.c — reject malformed UTF-8 subjects when /u is set.
     */
    private static function ensureUtf8SubjectForOpts(string $subject, int $opts, int $offset): bool
    {
        if (0 === ($opts & VmPregPattern::PCRE2_UTF)) {
            return true;
        }
        if (!VmPregUtf8::isValidUtf8($subject)) {
            self::$lastError = StdlibConstants::PREG_BAD_UTF8_ERROR;

            return false;
        }
        $subjectLen = \strlen($subject);
        if ($offset > 0 && $offset < $subjectLen) {
            $byte = \ord($subject[$offset]);
            if (($byte & 0xC0) === 0x80) {
                self::$lastError = StdlibConstants::PREG_BAD_UTF8_OFFSET_ERROR;

                return false;
            }
        }

        return true;
    }

    /**
     * @param list<int> $ovector
     * @param array<string, int> $groupNameToIndex
     *
     * @return array<int|string, string|array{0: string|null, 1: int}|null>
     */
    private static function ovectorToMatches(
        array $ovector,
        string $subject,
        array $groupNameToIndex,
        string $regex,
        bool $offsetCapture,
        bool $unmatchedNull,
        int $captureGroupCount = 0
    ): array {
        $groupCount = (int) (\count($ovector) / 2);
        if ($unmatchedNull && $captureGroupCount >= $groupCount) {
            $groupCount = $captureGroupCount + 1;
        }
        $out = [];
        for ($i = 0; $i < $groupCount; ++$i) {
            if ($i > 0) {
                foreach ($groupNameToIndex as $name => $groupNum) {
                    if ($groupNum === $i) {
                        $out[$name] = self::ovectorEntryToMatch($ovector, $i, $subject, $offsetCapture, $unmatchedNull);
                    }
                }
            }
            $out[$i] = self::ovectorEntryToMatch($ovector, $i, $subject, $offsetCapture, $unmatchedNull);
        }

        return $out;
    }

    /**
     * @param list<int> $ovector
     *
     * @return string|array{0: string|null, 1: int}|null
     */
    private static function ovectorEntryToMatch(
        array $ovector,
        int $index,
        string $subject,
        bool $offsetCapture,
        bool $unmatchedNull
    ): string|array|null {
        $start = $ovector[$index * 2] ?? -1;
        $end = $ovector[$index * 2 + 1] ?? -1;
        if ($start < 0 || $end < 0) {
            if ($offsetCapture) {
                return $unmatchedNull ? [null, -1] : ['', -1];
            }

            return $unmatchedNull ? null : '';
        }
        $piece = \substr($subject, $start, $end - $start);

        return $offsetCapture ? [$piece, $start] : $piece;
    }

    /**
     * @return list<string>|list<array{0: string, 1: int}>
     */
    private static function pregSplitEmptyPattern(
        string $subject,
        int $limit,
        int $flags,
        bool $utf8
    ): array {
        $offsetCapture = 0 !== ($flags & StdlibConstants::PREG_SPLIT_OFFSET_CAPTURE);
        $noEmpty = 0 !== ($flags & StdlibConstants::PREG_SPLIT_NO_EMPTY);
        if (1 === $limit) {
            self::$lastError = 0;

            return [$offsetCapture ? [$subject, 0] : $subject];
        }

        /** @var list<array{0: string, 1: int}> $units */
        $units = [];
        if ($utf8) {
            $charLen = VmPregUtf8::utf8CharLength($subject);
            $bytePos = 0;
            for ($i = 0; $i < $charLen; ++$i) {
                $ch = VmPregUtf8::utf8CharSubstr($subject, $i, 1);
                $units[] = [$ch, $bytePos];
                $bytePos += \strlen($ch);
            }
        } else {
            $byteLen = \strlen($subject);
            for ($i = 0; $i < $byteLen; ++$i) {
                $units[] = [$subject[$i], $i];
            }
        }

        $maxParts = $limit <= 0 ? \PHP_INT_MAX : $limit;
        $parts = [];
        $count = 0;
        $append = static function (string $piece, int $offset) use (
            &$parts,
            &$count,
            $maxParts,
            $offsetCapture,
            $noEmpty
        ): bool {
            if ($noEmpty && '' === $piece) {
                return true;
            }
            if ($count >= $maxParts) {
                return false;
            }
            $parts[] = $offsetCapture ? [$piece, $offset] : $piece;
            ++$count;

            return true;
        };

        if (!$append('', 0)) {
            self::$lastError = 0;

            return $parts;
        }

        $unitCount = \count($units);
        for ($i = 0; $i < $unitCount; ++$i) {
            if ($count >= $maxParts - 1) {
                $tail = '';
                for ($j = $i; $j < $unitCount; ++$j) {
                    $tail .= $units[$j][0];
                }
                $append($tail, $units[$i][1]);
                self::$lastError = 0;

                return $parts;
            }
            if (!$append($units[$i][0], $units[$i][1])) {
                self::$lastError = 0;

                return $parts;
            }
        }

        $append('', \strlen($subject));
        self::$lastError = 0;

        return $parts;
    }

    /**
     * @param array<string, int> $groupNameToIndex
     *
     * @return list<int>|null|false ovector, null when no match, false on backtrack limit
     */
    private static function engineMatch(
        VmPregAstNode $ast,
        array $groupNameToIndex,
        string $subject,
        int $offset,
        int $opts,
        bool $anchoredAttempt
    ): array|null|false {
        $result = VmPregEngine::match($ast, $groupNameToIndex, $subject, $offset, $opts, $anchoredAttempt);
        if (false === $result) {
            self::$lastError = VmPregEngine::consumeLastMatchLimitError()
                ?: StdlibConstants::PREG_BACKTRACK_LIMIT_ERROR;

            return false;
        }

        return $result;
    }
}
