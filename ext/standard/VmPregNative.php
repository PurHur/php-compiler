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
                    $matchData,
                    $subject,
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
                    $one = self::extractMatches($matchData, $subject, $offsetCapture, false);
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
        int $limit = -1
    ): string|array|null|false {
        if (\is_array($subject)) {
            $out = [];
            foreach ($subject as $key => $item) {
                if (!\is_string($item)) {
                    throw new \LogicException(
                        'preg_replace() array subject values must be strings in this compiler build'
                    );
                }
                $replaced = self::pregReplaceString($pattern, $replacement, $item, $limit);
                if (false === $replaced) {
                    return false;
                }
                $out[$key] = $replaced;
            }

            return $out;
        }

        return self::pregReplaceString($pattern, $replacement, $subject, $limit);
    }

    /**
     * @return list<string>|list<array{0: string, 1: int}>|false
     */
    public static function pregSplit(string $pattern, string $subject, int $limit = -1, int $flags = 0): array|false
    {
        $compiled = self::compile($pattern);
        if (null === $compiled) {
            return false;
        }

        [$code, $matchData] = $compiled;
        try {
            $subjectC = self::stringToC($subject);
            $parts = [];
            $offset = 0;
            $count = 0;
            $maxParts = $limit < 0 ? \PHP_INT_MAX : $limit;
            $offsetCapture = 0 !== ($flags & StdlibConstants::PREG_SPLIT_OFFSET_CAPTURE);
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
        int $limit
    ): string|false {
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

        $parsed = self::parsePhpPattern($pattern);
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

    /**
     * @return array{0: string, 1: int}|null
     */
    private static function parsePhpPattern(string $pattern): ?array
    {
        $len = \strlen($pattern);
        if ($len < 2) {
            return null;
        }
        $delimiter = $pattern[0];
        if (!self::isValidDelimiter($delimiter)) {
            return null;
        }

        $i = 1;
        while ($i < $len) {
            if ('\\' === $pattern[$i]) {
                if ($i + 1 < $len) {
                    $i += 2;
                    continue;
                }

                return null;
            }
            if ($pattern[$i] === $delimiter) {
                break;
            }
            $i++;
        }
        if ($i >= $len) {
            return null;
        }

        $regex = \substr($pattern, 1, $i - 1);
        $opts = 0;
        for ($j = $i + 1; $j < $len; $j++) {
            $mod = match ($pattern[$j]) {
                'i' => 0x00000008,
                'm' => 0x00000400,
                's' => 0x00000020,
                'x' => 0x00000080,
                'A' => 0x80000000,
                'D' => 0x00000010,
                'U' => 0x00040000,
                'u' => 0x00080000,
                default => null,
            };
            if (null === $mod) {
                return null;
            }
            $opts |= $mod;
        }

        return [$regex, $opts];
    }

    private static function isValidDelimiter(string $c): bool
    {
        if ('' === $c || '\\' === $c) {
            return false;
        }
        $ord = \ord($c);

        return !(($ord >= 0x30 && $ord <= 0x39) || ($ord >= 0x41 && $ord <= 0x5A) || ($ord >= 0x61 && $ord <= 0x7A));
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
     * @param mixed $matchData
     *
     * @return array<int|string, string|array{0: string|null, 1: int}|null>
     */
    private static function extractMatches(
        mixed $matchData,
        string $subject,
        bool $offsetCapture,
        bool $unmatchedNull
    ): array {
        $ovector = self::$ffi->pcre2_get_ovector_pointer_8($matchData);
        $count = (int) self::$ffi->pcre2_get_ovector_count_8($matchData);
        $out = [];
        for ($i = 0; $i < $count; $i++) {
            $start = (int) $ovector[$i * 2];
            $end = (int) $ovector[$i * 2 + 1];
            if ($start < 0 || $end < 0) {
                if ($offsetCapture) {
                    $out[$i] = $unmatchedNull ? [null, -1] : ['', -1];
                } else {
                    $out[$i] = $unmatchedNull ? null : '';
                }
                continue;
            }
            $piece = \substr($subject, $start, $end - $start);
            $out[$i] = $offsetCapture ? [$piece, $start] : $piece;
        }

        return $out;
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
