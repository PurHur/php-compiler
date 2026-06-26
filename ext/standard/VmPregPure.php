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
        $compiled = self::compile($pattern);
        if (null === $compiled) {
            return false;
        }

        $ovector = VmPregEngine::match(
            $compiled['ast'],
            $compiled['groupNameToIndex'],
            $subject,
            $offset,
            $compiled['opts'],
            self::fixedStartMatch($regex, $compiled['opts'])
        );
        if (null === $ovector) {
            self::$lastError = 0;
            if (null !== $matches) {
                $matches = [];
            }

            return 0;
        }

        self::$lastError = 0;
        if (null !== $matches) {
            $matches = self::ovectorToMatches(
                $ovector,
                $subject,
                $compiled['groupNameToIndex'],
                $regex,
                0 !== ($flags & StdlibConstants::PREG_OFFSET_CAPTURE),
                0 !== ($flags & StdlibConstants::PREG_UNMATCHED_AS_NULL)
            );
        }

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
        $compiled = self::compile($pattern);
        if (null === $compiled) {
            return false;
        }

        $start = $offset;
        $count = 0;
        $setOrder = 0 !== ($flags & StdlibConstants::PREG_SET_ORDER);
        $offsetCapture = 0 !== ($flags & StdlibConstants::PREG_OFFSET_CAPTURE);
        $allMatches = [];
        $subjectLen = \strlen($subject);
        $fixedStart = self::fixedStartMatch($regex, $compiled['opts']);

        while ($start <= $subjectLen) {
            $ovector = VmPregEngine::match(
                $compiled['ast'],
                $compiled['groupNameToIndex'],
                $subject,
                $start,
                $compiled['opts'],
                $fixedStart && $start === $offset
            );
            if (null === $ovector) {
                break;
            }
            ++$count;
            if (null !== $matches) {
                $one = self::ovectorToMatches($ovector, $subject, $compiled['groupNameToIndex'], $regex, $offsetCapture, false);
                if ($setOrder) {
                    $allMatches[] = $one;
                } else {
                    foreach ($one as $key => $val) {
                        $allMatches[$key][] = $val;
                    }
                }
            }
            $end = $ovector[1] ?? $start;
            $start = $end === ($ovector[0] ?? $start) ? $end + 1 : $end;
            if ($start > $subjectLen) {
                break;
            }
            $fixedStart = false;
        }

        if (null !== $matches) {
            $matches = $allMatches;
        }
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
                if (!\is_string($item)) {
                    throw new \LogicException(
                        'preg_replace() array subject values must be strings in this compiler build'
                    );
                }
                $elemCount = 0;
                $replaced = self::pregReplaceString($pattern, $replacement, $item, $limit, $elemCount);
                if (false === $replaced) {
                    return false;
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
            return self::pregSplitEmptyPattern(
                $subject,
                $limit,
                $flags,
                0 !== ($opts & 0x00080000)
            );
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
        $offset = 0;
        $count = 0;
        $maxParts = $limit <= 0 ? \PHP_INT_MAX : $limit;
        $noEmpty = 0 !== ($flags & StdlibConstants::PREG_SPLIT_NO_EMPTY);
        $delimCapture = 0 !== ($flags & StdlibConstants::PREG_SPLIT_DELIM_CAPTURE);
        $subjectLen = \strlen($subject);
        $fixedStart = self::fixedStartMatch($regex, $compiled['opts']);

        while ($offset <= $subjectLen && $count < $maxParts) {
            $ovector = VmPregEngine::match(
                $compiled['ast'],
                $compiled['groupNameToIndex'],
                $subject,
                $offset,
                $compiled['opts'],
                $fixedStart && 0 === $offset
            );
            if (null === $ovector) {
                $tail = \substr($subject, $offset);
                if (!$noEmpty || '' !== $tail) {
                    $parts[] = $offsetCapture ? [$tail, $offset] : $tail;
                }
                break;
            }
            $matchStart = $ovector[0] ?? $offset;
            $matchEnd = $ovector[1] ?? $offset;
            $chunk = \substr($subject, $offset, $matchStart - $offset);
            if (!$noEmpty || '' !== $chunk) {
                $parts[] = $offsetCapture ? [$chunk, $offset] : $chunk;
                ++$count;
            }
            if ($delimCapture) {
                $groupCount = (int) (\count($ovector) / 2);
                $startGi = $groupCount > 1 ? 1 : 0;
                for ($gi = $startGi; $gi < $groupCount; ++$gi) {
                    $gStart = $ovector[$gi * 2] ?? -1;
                    $gEnd = $ovector[$gi * 2 + 1] ?? -1;
                    if ($gStart < 0 || $gEnd < 0) {
                        continue;
                    }
                    $delim = \substr($subject, $gStart, $gEnd - $gStart);
                    if (!$noEmpty || '' !== $delim) {
                        $parts[] = $offsetCapture ? [$delim, $gStart] : $delim;
                        ++$count;
                    }
                }
            }
            $offset = $matchEnd;
            if ($count >= $maxParts - 1) {
                $tail = \substr($subject, $offset);
                if (!$noEmpty || '' !== $tail) {
                    $parts[] = $offsetCapture ? [$tail, $offset] : $tail;
                }
                break;
            }
            $fixedStart = false;
        }

        self::$lastError = 0;

        return $parts;
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
        int $flags = 0
    ): string|array|null|false {
        unset($flags);
        if (\is_array($subject)) {
            $out = [];
            foreach ($subject as $key => $item) {
                if (!\is_string($item)) {
                    throw new \LogicException(
                        'preg_filter() array subject values must be strings in this compiler build'
                    );
                }
                if (1 === self::pregMatch($pattern, $item)) {
                    $replaced = self::pregReplaceString($pattern, $replacement, $item, $limit);
                    if (false === $replaced) {
                        return false;
                    }
                    $out[$key] = $replaced;
                }
            }

            return [] === $out ? null : $out;
        }

        if (1 !== self::pregMatch($pattern, $subject)) {
            return null;
        }

        return self::pregReplaceString($pattern, $replacement, $subject, $limit);
    }

    public static function patternWarningMessage(string $pattern): ?string
    {
        return VmPregPattern::patternWarningMessage($pattern);
    }

    private static function pregReplaceString(
        string $pattern,
        string $replacement,
        string $subject,
        int $limit,
        ?int &$count = null
    ): string|false {
        $emptyParsed = PregEmptyPatternReplace::parseEmptyPattern($pattern);
        if (null !== $emptyParsed) {
            [, $opts] = $emptyParsed;
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

            return false;
        }
        [$regex, $opts] = $parsed;
        $compiled = self::compile($pattern);
        if (null === $compiled) {
            return false;
        }

        $out = '';
        $offset = 0;
        $replacements = 0;
        $max = $limit < 0 ? \PHP_INT_MAX : $limit;
        $subjectLen = \strlen($subject);
        $fixedStart = self::fixedStartMatch($regex, $compiled['opts']);

        while ($replacements < $max && $offset <= $subjectLen) {
            $ovector = VmPregEngine::match(
                $compiled['ast'],
                $compiled['groupNameToIndex'],
                $subject,
                $offset,
                $compiled['opts'],
                $fixedStart && $offset === 0
            );
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
     * @return array{ast: VmPregAstNode, groupNameToIndex: array<string, int>, regex: string, opts: int}|null
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
            self::$lastError = 4;

            return null;
        }
        [$ast, $groupNameToIndex] = $engineCompiled;

        return [
            'ast' => $ast,
            'groupNameToIndex' => $groupNameToIndex,
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
        bool $unmatchedNull
    ): array {
        $groupCount = (int) (\count($ovector) / 2);
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
            $charLen = VmString::utf8CharLength($subject);
            $bytePos = 0;
            for ($i = 0; $i < $charLen; ++$i) {
                $ch = VmString::utf8CharSubstr($subject, $i, 1);
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
}
