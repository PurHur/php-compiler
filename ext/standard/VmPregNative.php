<?php

declare(strict_types=1);

namespace PHPCompiler\ext\standard;

/**
 * Native preg_* for VM bootstrap (issue #4874).
 *
 * Uses libpcre2-8 via FFI — no host \\preg_* calls. Mirrors {@see StringPregMatchJit} semantics.
 */
final class VmPregNative
{
    private static int $lastError = 0;

    /** @var \FFI|null */
    private static $ffi = null;

    private static bool $ffiUnavailable = false;

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
        [$regex, $_opts] = $parsed;
        $compiled = self::compile($pattern);
        if (null === $compiled) {
            return false;
        }

        [$code, $matchData] = $compiled;
        try {
            $subjectC = self::stringToC($subject);
            $matchFlags = 0;

            $rc = (int) self::$ffi->pcre2_match_8(
                $code,
                $subjectC,
                \strlen($subject),
                $offset,
                $matchFlags,
                $matchData,
                null
            );

            if (-1 === $rc) {
                self::$lastError = 0;
                if (null !== $matches) {
                    $matches = [];
                }

                return 0;
            }
            if ($rc < 0) {
                self::$lastError = self::mapPcre2Error($rc);

                return false;
            }

            self::$lastError = 0;
            if (null !== $matches) {
                $matches = self::extractMatches(
                    $code,
                    $matchData,
                    $subject,
                    $regex,
                    0 !== ($flags & StdlibConstants::PREG_OFFSET_CAPTURE),
                    0 !== ($flags & StdlibConstants::PREG_UNMATCHED_AS_NULL)
                );
            }

            return $rc > 0 ? 1 : 0;
        } finally {
            self::$ffi->pcre2_match_data_free_8($matchData);
            self::$ffi->pcre2_code_free_8($code);
        }
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
        [$regex, $_opts] = $parsed;
        $compiled = self::compile($pattern);
        if (null === $compiled) {
            return false;
        }

        [$code, $matchData] = $compiled;
        try {
            $subjectC = self::stringToC($subject);
            $start = $offset;
            $count = 0;
            $setOrder = 0 !== ($flags & StdlibConstants::PREG_SET_ORDER);
            $offsetCapture = 0 !== ($flags & StdlibConstants::PREG_OFFSET_CAPTURE);
            $allMatches = [];

            while ($start <= \strlen($subject)) {
                $rc = (int) self::$ffi->pcre2_match_8(
                    $code,
                    $subjectC,
                    \strlen($subject),
                    $start,
                    0,
                    $matchData,
                    null
                );
                if (-1 === $rc) {
                    break;
                }
                if ($rc < 0) {
                    self::$lastError = self::mapPcre2Error($rc);

                    return false;
                }
                $count++;
                if (null !== $matches) {
                    $one = self::extractMatches($code, $matchData, $subject, $regex, $offsetCapture, false);
                    if ($setOrder) {
                        $allMatches[] = $one;
                    } else {
                        foreach ($one as $key => $val) {
                            $allMatches[$key][] = $val;
                        }
                    }
                }
                $ovector = self::$ffi->pcre2_get_ovector_pointer_8($matchData);
                $end = (int) $ovector[1];
                $start = $end === (int) $ovector[0] ? $end + 1 : $end;
                if ($start > \strlen($subject)) {
                    break;
                }
            }

            if (null !== $matches) {
                $matches = $allMatches;
            }
            self::$lastError = 0;

            return $count;
        } finally {
            self::$ffi->pcre2_match_data_free_8($matchData);
            self::$ffi->pcre2_code_free_8($code);
        }
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

        [$code, $matchData] = $compiled;
        try {
            $offsetCapture = 0 !== ($flags & StdlibConstants::PREG_SPLIT_OFFSET_CAPTURE);
            // php-src: limit 1 returns the subject unsplit; limit <= 0 is unlimited (#10545).
            if (1 === $limit) {
                self::$lastError = 0;

                return [$offsetCapture ? [$subject, 0] : $subject];
            }

            $subjectC = self::stringToC($subject);
            $parts = [];
            $offset = 0;
            $count = 0;
            $maxParts = $limit <= 0 ? \PHP_INT_MAX : $limit;
            $noEmpty = 0 !== ($flags & StdlibConstants::PREG_SPLIT_NO_EMPTY);
            $delimCapture = 0 !== ($flags & StdlibConstants::PREG_SPLIT_DELIM_CAPTURE);

            while ($offset <= \strlen($subject) && $count < $maxParts) {
                $rc = (int) self::$ffi->pcre2_match_8(
                    $code,
                    $subjectC,
                    \strlen($subject),
                    $offset,
                    0,
                    $matchData,
                    null
                );
                if (-1 === $rc) {
                    $tail = \substr($subject, $offset);
                    if (!$noEmpty || '' !== $tail) {
                        $parts[] = $offsetCapture ? [$tail, $offset] : $tail;
                    }
                    break;
                }
                if ($rc < 0) {
                    self::$lastError = self::mapPcre2Error($rc);

                    return false;
                }
                $ovector = self::$ffi->pcre2_get_ovector_pointer_8($matchData);
                $matchStart = (int) $ovector[0];
                $matchEnd = (int) $ovector[1];
                $chunk = \substr($subject, $offset, $matchStart - $offset);
                if (!$noEmpty || '' !== $chunk) {
                    $parts[] = $offsetCapture ? [$chunk, $offset] : $chunk;
                    ++$count;
                }
                if ($delimCapture) {
                    $ovectorCount = (int) self::$ffi->pcre2_get_ovector_count_8($matchData);
                    $startGi = $ovectorCount > 1 ? 1 : 0;
                    if ($startGi >= $ovectorCount) {
                        $startGi = $ovectorCount;
                    }
                    for ($gi = $startGi; $gi < $ovectorCount; ++$gi) {
                        $gStart = (int) $ovector[$gi * 2];
                        $gEnd = (int) $ovector[$gi * 2 + 1];
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
            }

            self::$lastError = 0;

            return $parts;
        } finally {
            self::$ffi->pcre2_match_data_free_8($matchData);
            self::$ffi->pcre2_code_free_8($code);
        }
    }

    /**
     * php-src php_pcre_split empty-regex fast path (// and //u, #10967).
     *
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

        /** @var list<array{0: string, 1: int}> $units byte offset + segment */
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

        $compiled = self::compile($pattern);
        if (null === $compiled) {
            return false;
        }

        [$code, $matchData] = $compiled;
        try {
            $subjectC = self::stringToC($subject);
            $out = '';
            $offset = 0;
            $replacements = 0;
            $max = $limit < 0 ? \PHP_INT_MAX : $limit;

            while ($replacements < $max && $offset <= \strlen($subject)) {
                $rc = (int) self::$ffi->pcre2_match_8(
                    $code,
                    $subjectC,
                    \strlen($subject),
                    $offset,
                    0,
                    $matchData,
                    null
                );
                if (-1 === $rc) {
                    break;
                }
                if ($rc < 0) {
                    self::$lastError = self::mapPcre2Error($rc);

                    return false;
                }
                $ovector = self::$ffi->pcre2_get_ovector_pointer_8($matchData);
                $start = (int) $ovector[0];
                $end = (int) $ovector[1];
                $out .= \substr($subject, $offset, $start - $offset);
                $out .= PregReplacementExpand::expand(
                    $replacement,
                    self::$ffi->pcre2_get_ovector_pointer_8($matchData),
                    (int) self::$ffi->pcre2_get_ovector_count_8($matchData),
                    $subject
                );
                $offset = $end;
                $replacements++;
                if ($end === $start) {
                    $offset = $end + 1;
                }
                if ($offset > \strlen($subject)) {
                    break;
                }
            }

            if ($offset < \strlen($subject)) {
                $out .= \substr($subject, $offset);
            }

            self::$lastError = 0;
            if (null !== $count) {
                $count = $replacements;
            }

            return $out;
        } finally {
            self::$ffi->pcre2_match_data_free_8($matchData);
            self::$ffi->pcre2_code_free_8($code);
        }
    }

    /**
     * @return array{0: mixed, 1: mixed}|null
     */
    private static function compile(string $pattern): ?array
    {
        self::ensureFfi();
        if (null === self::$ffi) {
            self::$lastError = 1;

            return null;
        }

        $parsed = VmPregPattern::parsePhpPattern($pattern);
        if (null === $parsed) {
            self::$lastError = 1;

            return null;
        }

        [$regex, $opts] = $parsed;
        $regexC = self::stringToC($regex);
        $errorCode = self::$ffi->new('int');
        $errorOffset = self::$ffi->new('size_t');
        $code = self::$ffi->pcre2_compile_8(
            $regexC,
            \strlen($regex),
            $opts,
            \FFI::addr($errorCode),
            \FFI::addr($errorOffset),
            null
        );
        if (null === $code) {
            self::$lastError = self::mapPcre2Error((int) $errorCode->cdata);

            return null;
        }

        $matchData = self::$ffi->pcre2_match_data_create_from_pattern_8($code, null);
        if (null === $matchData) {
            self::$ffi->pcre2_code_free_8($code);
            self::$lastError = 1;

            return null;
        }

        return [$code, $matchData];
    }

    /**
     * @return \FFI\CData
     */
    private static function stringToC(string $value): \FFI\CData
    {
        $c = self::$ffi->new('uint8_t['.(\strlen($value) + 1).']', false);
        \FFI::memcpy($c, $value, \strlen($value));

        return $c;
    }

    public static function patternWarningMessage(string $pattern): ?string
    {
        return VmPregPattern::patternWarningMessage($pattern);
    }

    private static function mapPcre2Error(int $code): int
    {
        if (0 === $code) {
            return 0;
        }
        if ($code >= -44 && $code <= -2) {
            return 4;
        }

        return match ($code) {
            -48 => 5,
            -8 => 2,
            -9 => 3,
            -45 => 6,
            default => 1,
        };
    }

    /**
     * @param mixed $code
     * @param mixed $matchData
     *
     * @return array<int|string, string|array{0: string|null, 1: int}|null>
     */
    private static function extractMatches(
        mixed $code,
        mixed $matchData,
        string $subject,
        string $regex,
        bool $offsetCapture,
        bool $unmatchedNull
    ): array {
        $ovector = self::$ffi->pcre2_get_ovector_pointer_8($matchData);
        $count = (int) self::$ffi->pcre2_get_ovector_count_8($matchData);
        $out = [];
        for ($i = 0; $i < $count; ++$i) {
            $out[$i] = self::ovectorEntryToMatch($ovector, $i, $subject, $offsetCapture, $unmatchedNull);
        }
        self::appendNamedCaptureGroups($code, $ovector, $subject, $regex, $out, $offsetCapture, $unmatchedNull);

        return $out;
    }

    /**
     * @param mixed $ovector
     *
     * @return string|array{0: string|null, 1: int}|null
     */
    private static function ovectorEntryToMatch(
        mixed $ovector,
        int $index,
        string $subject,
        bool $offsetCapture,
        bool $unmatchedNull
    ): string|array|null {
        $start = (int) $ovector[$index * 2];
        $end = (int) $ovector[$index * 2 + 1];
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
     * @param mixed $code
     * @param mixed $ovector
     * @param array<int|string, string|array{0: string|null, 1: int}|null> $out
     */
    private static function appendNamedCaptureGroups(
        mixed $code,
        mixed $ovector,
        string $subject,
        string $regex,
        array &$out,
        bool $offsetCapture,
        bool $unmatchedNull
    ): void {
        if (!\preg_match_all('/\(\?(?:P<|<)([A-Za-z_]\w*)/', $regex, $names)) {
            return;
        }
        foreach ($names[1] as $name) {
            $nameC = self::stringToC($name);
            $groupNum = (int) self::$ffi->pcre2_substring_number_from_name_8($code, $nameC);
            if ($groupNum <= 0) {
                continue;
            }
            $out[$name] = self::ovectorEntryToMatch(
                $ovector,
                $groupNum,
                $subject,
                $offsetCapture,
                $unmatchedNull
            );
        }
    }

    private static function ensureFfi(): void
    {
        if (self::$ffiUnavailable) {
            return;
        }
        if (null !== self::$ffi) {
            return;
        }
        if (!\extension_loaded('ffi')) {
            self::$ffiUnavailable = true;

            return;
        }

        $cdef = <<<'CDEF'
typedef unsigned char PCRE2_UCHAR8;
typedef PCRE2_UCHAR8 *PCRE2_SPTR8;
typedef struct pcre2_code_8 pcre2_code_8;
typedef struct pcre2_match_data_8 pcre2_match_data_8;
typedef size_t PCRE2_SIZE;

pcre2_code_8 *pcre2_compile_8(PCRE2_SPTR8, PCRE2_SIZE, uint32_t, int *, PCRE2_SIZE *, void *);
void pcre2_code_free_8(pcre2_code_8 *);
pcre2_match_data_8 *pcre2_match_data_create_from_pattern_8(const pcre2_code_8 *, void *);
void pcre2_match_data_free_8(pcre2_match_data_8 *);
int pcre2_match_8(const pcre2_code_8 *, PCRE2_SPTR8, PCRE2_SIZE, PCRE2_SIZE, uint32_t, pcre2_match_data_8 *, void *);
PCRE2_SIZE *pcre2_get_ovector_pointer_8(pcre2_match_data_8 *);
uint32_t pcre2_get_ovector_count_8(pcre2_match_data_8 *);
int pcre2_substring_number_from_name_8(const pcre2_code_8 *, PCRE2_SPTR8);
int pcre2_substitute_8(const pcre2_code_8 *, PCRE2_SPTR8, PCRE2_SIZE, PCRE2_SIZE, uint32_t, pcre2_match_data_8 *, void *, PCRE2_SPTR8, PCRE2_SIZE, PCRE2_UCHAR8 **, PCRE2_SIZE *);
void pcre2_substring_free_8(PCRE2_UCHAR8 *);
CDEF;

        foreach (['libpcre2-8.so.0', 'libpcre2-8.so', 'libpcre2-8'] as $lib) {
            try {
                self::$ffi = \FFI::cdef($cdef, $lib);
                if (null !== self::$ffi) {
                    return;
                }
            } catch (\Throwable) {
                continue;
            }
        }

        self::$ffiUnavailable = true;
    }
}
