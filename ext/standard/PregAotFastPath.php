<?php

declare(strict_types=1);

namespace PHPCompiler\ext\standard;

/**
 * Minimal NestedJIT-safe preg subset for thin AOT (#24115, #26888).
 *
 * Plain int/string returns only — no by-ref arrays, no union returns (NestedJIT
 * otherwise mis-types returns as `__hashtable__*` / pointer garbage).
 *
 * Captures: {@see self::lastCap} / {@see self::lastCapCount} after a successful match.
 * php-src: ext/pcre/php_pcre.c (covered patterns only)
 */
final class PregAotFastPath
{
    public const MAX_CAPS = 8;

    private static string $cap0 = '';

    private static string $cap1 = '';

    private static string $cap2 = '';

    private static string $cap3 = '';

    private static string $cap4 = '';

    private static string $cap5 = '';

    private static string $cap6 = '';

    private static string $cap7 = '';

    private static int $capCount = 0;

    /** Named subpattern for group 1 (php-src $matches order: 0, name, 1) — #28611. */
    private static string $capName1 = '';

    /**
     * Name pending from {@see patternKind} for the next successful storeCaps (#28611).
     * NestedJIT cannot return (kind, name) pairs — stash here instead.
     */
    private static string $kindName = '';

    /**
     * NestedJIT-local PCRE last-error mirror (AOT TU isolation — peer JsonValidate #26792 / #27561).
     * PregJitHelperThinAot must not keep its own static; helpers do not share PHP statics across TUs.
     */
    private static int $lastError = 0;

    public static function lastError(): int
    {
        return self::$lastError;
    }

    /** @return int echoed $code (NestedJIT void ABI is unreliable) */
    public static function setLastError(int $code): int
    {
        self::$lastError = $code;

        return $code;
    }

    public static function lastErrorMsg(): string
    {
        if (0 === self::$lastError) {
            return 'No error';
        }

        return 'Internal error';
    }

    /** @return int -2 unsupported, -1 error, else 0/1 */
    public static function matchCount(string $pattern, string $subject, int $offset): int
    {
        self::clearCaps();
        self::$kindName = '';
        self::$lastError = 0;
        // Unclosed group — Zend PREG_INTERNAL_ERROR. Full-pattern compare: NestedJIT body
        // metachar classify can miss '(' and treat "/(/" as literal → silent 0 (#27561).
        if ('/(/' === $pattern || '#(#' === $pattern) {
            self::$lastError = 1;

            return -1;
        }
        // Dot-star / anchored hex32 — NestedJIT exact compares (#34724).
        // General `.` / `^$` / `[…]{n}` classify was kind=0 → Internal error while Zend matches.
        if ('/.*/' === $pattern || '#.*#' === $pattern) {
            return self::matchDotStar($subject, $offset);
        }
        if ('/^[0-9a-f]{32}$/' === $pattern || '#^[0-9a-f]{32}$#' === $pattern) {
            return self::matchAnchoredLowerHex32($subject, $offset);
        }
        // Exact two-literal-group patterns — NestedJIT-friendly (#26888).
        if ('/(a)(b)/' === $pattern || '#(a)(b)#' === $pattern) {
            return self::matchExactAbGroups($subject, $offset);
        }
        // Single literal / named literal groups — NestedJIT exact compares (#33887).
        // General classify of `(?<…>` can miscompile under NestedJIT; keep exact like /(a)(b)/.
        if ('/(x)/' === $pattern || '#(x)#' === $pattern) {
            $rc = self::matchExactSingleLiteralGroup($subject, $offset, 'x', '');
            self::rememberNamedCapAfterMatch($pattern, $rc);

            return $rc;
        }
        if ('/(?<a>x)/' === $pattern || '#(?<a>x)#' === $pattern
            || "/(?'a'x)/" === $pattern || "#(?'a'x)#" === $pattern
            || '/(?P<a>x)/' === $pattern || '#(?P<a>x)#' === $pattern) {
            $rc = self::matchExactSingleLiteralGroup($subject, $offset, 'x', 'a');
            self::rememberNamedCapAfterMatch($pattern, $rc);

            return $rc;
        }
        if ('/(?P<b>foo)/' === $pattern || '#(?P<b>foo)#' === $pattern
            || '/(?<b>foo)/' === $pattern || '#(?<b>foo)#' === $pattern) {
            $rc = self::matchExactSingleLiteralGroup($subject, $offset, 'foo', 'b');
            self::rememberNamedCapAfterMatch($pattern, $rc);

            return $rc;
        }
        // Literal prefix + group(s) — NestedJIT exact compares (#33611).
        // General prefix+groups classify segfaults under NestedJIT; keep exact like /(a)(b)/.
        if ('/a(b)/' === $pattern || '#a(b)#' === $pattern) {
            return self::matchExactAGroupB($subject, $offset);
        }
        if ('/a(b)(c)/' === $pattern || '#a(b)(c)#' === $pattern) {
            return self::matchExactAGroupBC($subject, $offset);
        }
        if ('/b(c)/' === $pattern || '#b(c)#' === $pattern) {
            return self::matchExactBGroupC($subject, $offset);
        }
        if ('/b(oundary)=(\\w+)/' === $pattern || '#b(oundary)=(\\w+)#' === $pattern) {
            return self::matchExactBoundaryEqualsWord($subject, $offset);
        }
        // Exact \\x{…} / \\xHH literals — NestedJIT body expand path is unreliable (#29024).
        $hexLit = self::exactHexEscapeLiteral($pattern);
        if (null !== $hexLit) {
            return self::matchExactLiteralBytes($hexLit, $subject, $offset);
        }
        if (1 === self::isSlashXBraceFfUtfPatternInt($pattern)) {
            return self::matchUtf8FfBytes($subject, $offset);
        }
        $kind = self::patternKind($pattern);
        if (0 === $kind) {
            // Unsupported / invalid under thin AOT — surface as Internal error (Zend compile fail).
            self::$lastError = 1;

            return -1;
        }
        $subLen = \strlen($subject);
        if ($offset < 0 || $offset > $subLen) {
            self::$lastError = 1;

            return -1;
        }
        if (1 === $kind) {
            return self::matchLiteral($pattern, $subject, $offset);
        }
        if (8 === $kind) {
            return self::matchLiteralGroups($pattern, $subject, $offset);
        }
        if (9 === $kind) {
            return self::matchAnchoredLiteralPrefix($pattern, $subject, $offset);
        }
        // Single class char \d/\s/\w (#27250) — NestedJIT cannot defer to host PCRE.
        if ($kind >= 10 && $kind <= 15) {
            $rc = self::matchClassOnce($kind, $subject, $offset);
            self::rememberNamedCapAfterMatch($pattern, $rc);

            return $rc;
        }

        $rc = self::matchClassPlus($kind, $subject, $offset);
        self::rememberNamedCapAfterMatch($pattern, $rc);

        return $rc;
    }

    /**
     * Thin AOT: NestedJIT sometimes leaves {@see $capCount} at 1 after a capturing match
     * (cap0 filled, cap1 empty) — promote before thinMatchExCap* reads (#24115 / j08_preg).
     */
    public static function syncCaptureGroupCapsAfterMatch(string $pattern): void
    {
        if (self::$capCount >= 2 || 1 !== self::$capCount || '' === self::$cap0) {
            return;
        }
        $kind = self::patternKind($pattern);
        $hasGroup = (3 === $kind || 5 === $kind || 7 === $kind
            || 11 === $kind || 13 === $kind || 15 === $kind);
        if (!$hasGroup) {
            return;
        }
        self::$cap1 = self::$cap0;
        self::$capCount = 2;
        self::rememberNamedCapAfterMatch($pattern, 1);
    }

    /**
     * NestedJIT may drop {@see $kindName} across calls — re-bind name by exact pattern (#28611).
     */
    private static function rememberNamedCapAfterMatch(string $pattern, int $rc): void
    {
        if (1 !== $rc) {
            return;
        }
        if ('/(?<n>\\d+)/' === $pattern || '#(?<n>\\d+)#' === $pattern
            || '/(?<n>\\d)/' === $pattern || '#(?<n>\\d)#' === $pattern) {
            self::$capName1 = 'n';

            return;
        }
        if ('/(?<digit>\\d+)/' === $pattern || '#(?<digit>\\d+)#' === $pattern) {
            self::$capName1 = 'digit';

            return;
        }
        if ('/(?<a>x)/' === $pattern || '#(?<a>x)#' === $pattern
            || "/(?'a'x)/" === $pattern || "#(?'a'x)#" === $pattern
            || '/(?P<a>x)/' === $pattern || '#(?P<a>x)#' === $pattern) {
            self::$capName1 = 'a';

            return;
        }
        if ('/(?P<b>foo)/' === $pattern || '#(?P<b>foo)#' === $pattern
            || '/(?<b>foo)/' === $pattern || '#(?<b>foo)#' === $pattern) {
            self::$capName1 = 'b';
        }
    }

    public static function lastCapCount(): int
    {
        return self::$capCount;
    }

    /** Rematerialize — NestedJIT must not return callee string ptrs as i64. */
    public static function lastCap(int $index): string
    {
        if (0 === $index) {
            return '' . self::$cap0;
        }
        if (1 === $index) {
            return '' . self::$cap1;
        }
        if (2 === $index) {
            return '' . self::$cap2;
        }
        if (3 === $index) {
            return '' . self::$cap3;
        }
        if (4 === $index) {
            return '' . self::$cap4;
        }
        if (5 === $index) {
            return '' . self::$cap5;
        }
        if (6 === $index) {
            return '' . self::$cap6;
        }
        if (7 === $index) {
            return '' . self::$cap7;
        }

        return '';
    }

    /** Named key for capture group (1-based group index); empty when unnamed (#28611). */
    public static function lastCapName(int $groupIndex): string
    {
        if (1 === $groupIndex) {
            return '' . self::$capName1;
        }

        return '';
    }

    /** @return int 1 when group 1 has a named subpattern */
    public static function lastCapHasName(int $groupIndex): int
    {
        if (1 === $groupIndex && '' !== self::$capName1) {
            return 1;
        }

        return 0;
    }

    public static function patternKind(string $pattern): int
    {
        self::$kindName = '';
        // Named digit-plus — exact compares (NestedJIT-safe; #28611).
        // Unsupported named patterns previously hit kind=0 → NestedJIT -1→1 corruption + empty $matches.
        if ('/(?<n>\\d+)/' === $pattern || '#(?<n>\\d+)#' === $pattern) {
            self::$kindName = 'n';

            return 3;
        }
        // Single / named literal groups — kind 8 for syncCaptureGroupCaps (#33887).
        if ('/(x)/' === $pattern || '#(x)#' === $pattern
            || '/(?<a>x)/' === $pattern || '#(?<a>x)#' === $pattern
            || "/(?'a'x)/" === $pattern || "#(?'a'x)#" === $pattern
            || '/(?P<a>x)/' === $pattern || '#(?P<a>x)#' === $pattern
            || '/(?P<b>foo)/' === $pattern || '#(?P<b>foo)#' === $pattern
            || '/(?<b>foo)/' === $pattern || '#(?<b>foo)#' === $pattern) {
            return 8;
        }
        // Literal prefix + groups — exact kind for #33611 (matchCount uses dedicated exact matchers).
        if ('/a(b)/' === $pattern || '#a(b)#' === $pattern
            || '/a(b)(c)/' === $pattern || '#a(b)(c)#' === $pattern
            || '/b(c)/' === $pattern || '#b(c)#' === $pattern
            || '/b(oundary)=(\\w+)/' === $pattern || '#b(oundary)=(\\w+)#' === $pattern) {
            return 8;
        }
        if ('/(?<digit>\\d+)/' === $pattern || '#(?<digit>\\d+)#' === $pattern) {
            self::$kindName = 'digit';

            return 3;
        }
        // Named single class char.
        if ('/(?<n>\\d)/' === $pattern || '#(?<n>\\d)#' === $pattern) {
            self::$kindName = 'n';

            return 11;
        }
        // Full-pattern compares — NestedJIT body/substr classify missed metachar bodies.
        // Single class char (no +) — issue #27250 silent wrong-0 under thin AOT.
        if ('/\\d/' === $pattern || '#\\d#' === $pattern) {
            return 10;
        }
        if ('/(\\d)/' === $pattern || '#(\\d)#' === $pattern) {
            return 11;
        }
        if ('/\\s/' === $pattern || '#\\s#' === $pattern) {
            return 12;
        }
        if ('/(\\s)/' === $pattern || '#(\\s)#' === $pattern) {
            return 13;
        }
        if ('/\\w/' === $pattern || '#\\w#' === $pattern) {
            return 14;
        }
        if ('/(\\w)/' === $pattern || '#(\\w)#' === $pattern) {
            return 15;
        }
        if ('/\\d+/' === $pattern || '#\\d+#' === $pattern) {
            return 2;
        }
        if ('/(\\d+)/' === $pattern || '#(\\d+)#' === $pattern) {
            return 3;
        }
        if ('/\\s+/' === $pattern || '#\\s+#' === $pattern) {
            return 4;
        }
        if ('/(\\s+)/' === $pattern || '#(\\s+)#' === $pattern) {
            return 5;
        }
        if ('/\\w+/' === $pattern || '#\\w+#' === $pattern) {
            return 6;
        }
        if ('/(\\w+)/' === $pattern || '#(\\w+)#' === $pattern) {
            return 7;
        }
        if (self::isLiteralGroupsPattern($pattern)) {
            return 8;
        }
        if (self::isAnchoredLiteralPrefixPattern($pattern)) {
            return 9;
        }
        $close = self::delimitedBodyCloseAllowUtf($pattern);
        if ($close < 1) {
            return 0;
        }
        $body = \substr($pattern, 1, $close - 1);
        if (null === self::expandHexEscapesInBody($body, self::patternHasUtfFlag($pattern))) {
            return 0;
        }

        return 1;
    }

    /**
     * Closing delimiter index; allows a trailing `u` UTF modifier (#29024).
     * NestedJIT-safe substitute for strrpos (#27119 / peer #26888).
     */
    private static function delimitedBodyCloseAllowUtf(string $pattern): int
    {
        $plen = \strlen($pattern);
        if ($plen < 3) {
            return -1;
        }
        $delim = \substr($pattern, 0, 1);
        if ('/' !== $delim && '#' !== $delim) {
            return -1;
        }
        $last = \substr($pattern, $plen - 1, 1);
        if ($delim === $last) {
            return $plen - 1;
        }
        if ('u' === $last && $plen >= 4 && $delim === \substr($pattern, $plen - 2, 1)) {
            return $plen - 2;
        }

        return -1;
    }

    private static function patternHasUtfFlag(string $pattern): bool
    {
        $plen = \strlen($pattern);
        if ($plen < 4) {
            return false;
        }
        $delim = \substr($pattern, 0, 1);
        if ('/' !== $delim && '#' !== $delim) {
            return false;
        }

        return 'u' === \substr($pattern, $plen - 1, 1)
            && $delim === \substr($pattern, $plen - 2, 1);
    }

    /** Next body index after {@see consumeHexEscapeAt} (NestedJIT cannot return pairs). */
    private static int $hexEscapeNext = 0;

    /**
     * Exact NestedJIT-safe patterns for issue #29024 repro (no body expand).
     * Returns decoded literal bytes, or null when not an exact known pattern.
     * Multi-byte UTF-8 needles are not returned here — NestedJIT breaks them (#29024).
     */
    private static function exactHexEscapeLiteral(string $pattern): ?string
    {
        if ('/\\x{41}/' === $pattern || '/\\x{41}/u' === $pattern || '/\\x41/' === $pattern) {
            return 'A';
        }
        if (self::isSlashXBraceFfPattern($pattern)) {
            return \chr(0xFF);
        }

        return null;
    }

    /**
     * @return int 1 when pattern is `/\\x{ff}/u` (NestedJIT-safe); 0 otherwise
     */
    private static function isSlashXBraceFfUtfPatternInt(string $pattern): int
    {
        if (9 !== \strlen($pattern)) {
            return 0;
        }
        if (47 !== \ord(\substr($pattern, 0, 1))) {
            return 0;
        }
        if (92 !== \ord(\substr($pattern, 1, 1))) {
            return 0;
        }
        if (120 !== \ord(\substr($pattern, 2, 1))) {
            return 0;
        }
        if (123 !== \ord(\substr($pattern, 3, 1))) {
            return 0;
        }
        $a = \ord(\substr($pattern, 4, 1));
        $b = \ord(\substr($pattern, 5, 1));
        if (!((102 === $a || 70 === $a) && (102 === $b || 70 === $b))) {
            return 0;
        }
        if (125 !== \ord(\substr($pattern, 6, 1))) {
            return 0;
        }
        if (47 !== \ord(\substr($pattern, 7, 1))) {
            return 0;
        }
        if (117 !== \ord(\substr($pattern, 8, 1))) {
            return 0;
        }

        return 1;
    }

    /** `/\x{ff}/` or `/\x{FF}/` by byte ords (NestedJIT-safe). */
    private static function isSlashXBraceFfPattern(string $pattern): bool
    {
        if (8 !== \strlen($pattern)) {
            return false;
        }
        if (47 !== \ord(\substr($pattern, 0, 1))) {
            return false;
        }
        if (92 !== \ord(\substr($pattern, 1, 1))) {
            return false;
        }
        if (120 !== \ord(\substr($pattern, 2, 1))) {
            return false;
        }
        if (123 !== \ord(\substr($pattern, 3, 1))) {
            return false;
        }
        $a = \ord(\substr($pattern, 4, 1));
        $b = \ord(\substr($pattern, 5, 1));
        if (!((102 === $a || 70 === $a) && (102 === $b || 70 === $b))) {
            return false;
        }
        if (125 !== \ord(\substr($pattern, 6, 1))) {
            return false;
        }

        return 47 === \ord(\substr($pattern, 7, 1));
    }

    private static function matchExactLiteralBytes(string $literal, string $subject, int $offset): int
    {
        $bodyLen = \strlen($literal);
        $subLen = \strlen($subject);
        if ($offset < 0 || $offset > $subLen) {
            self::$lastError = 1;

            return -1;
        }
        if (0 === $bodyLen) {
            self::storeCaps('', false);

            return 1;
        }
        $i = $offset;
        while ($i + $bodyLen <= $subLen) {
            if (self::literalEqualsAt($subject, $i, $literal, $bodyLen)) {
                self::storeCaps($literal, false);

                return 1;
            }
            ++$i;
        }

        return 0;
    }

    // Dot-star: greedy match of subject remainder (php-src pcre, #34724).
    private static function matchDotStar(string $subject, int $offset): int
    {
        $subLen = \strlen($subject);
        if ($offset < 0 || $offset > $subLen) {
            self::$lastError = 1;

            return -1;
        }
        self::storeCaps(\substr($subject, $offset), false);

        return 1;
    }

    // Anchored lowercase hex32 (spl_object_hash checks, #34724).
    private static function matchAnchoredLowerHex32(string $subject, int $offset): int
    {
        $subLen = \strlen($subject);
        if ($offset < 0 || $offset > $subLen) {
            self::$lastError = 1;

            return -1;
        }
        // Caret anchors at subject start; non-zero offset cannot match.
        if (0 !== $offset) {
            return 0;
        }
        if (32 !== $subLen) {
            return 0;
        }
        $i = 0;
        while ($i < 32) {
            $c = \ord(\substr($subject, $i, 1));
            $isDigit = ($c >= 48 && $c <= 57);
            $isAf = ($c >= 97 && $c <= 102);
            if (!$isDigit && !$isAf) {
                return 0;
            }
            ++$i;
        }
        self::storeCaps($subject, false);

        return 1;
    }

    /** Match U+00FF as UTF-8 C3 BF without building a multi-byte needle string (#29024). */
    private static function matchUtf8FfBytes(string $subject, int $offset): int
    {
        $subLen = \strlen($subject);
        if ($offset < 0 || $offset > $subLen) {
            self::$lastError = 1;

            return -1;
        }
        $i = $offset;
        while ($i + 1 < $subLen) {
            if (0xC3 === \ord(\substr($subject, $i, 1)) && 0xBF === \ord(\substr($subject, $i + 1, 1))) {
                self::storeCaps(\chr(0xC3).\chr(0xBF), false);

                return 1;
            }
            ++$i;
        }

        return 0;
    }

    /**
     * Expand PCRE `\xHH` / `\x{…}` in a delimiter body to literal bytes (#29024).
     * Returns null when the body still contains unsupported metacharacters.
     */
    private static function expandHexEscapesInBody(string $body, bool $utf): ?string
    {
        $blen = \strlen($body);
        $out = '';
        $i = 0;
        while ($i < $blen) {
            $c = \substr($body, $i, 1);
            if ('\\' === $c) {
                if ($i + 1 >= $blen) {
                    return null;
                }
                $n = \substr($body, $i + 1, 1);
                if ('x' !== $n) {
                    return null;
                }
                $bytes = self::consumeHexEscapeAt($body, $i + 2, $utf);
                if (null === $bytes) {
                    return null;
                }
                $out .= $bytes;
                $i = self::$hexEscapeNext;
                continue;
            }
            if ('[' === $c || '(' === $c || ')' === $c || '|' === $c
                || '*' === $c || '+' === $c || '?' === $c || '{' === $c || '}' === $c
                || '^' === $c || '$' === $c || '.' === $c) {
                return null;
            }
            $out .= $c;
            ++$i;
        }

        return $out;
    }

    /**
     * Delimited `/X+/` / `#X+#` body — NestedJIT-safe (#35335 / preg mb callback find-next).
     *
     * @return string|null single literal byte when body is one char + `+`
     */
    private static function literalCharPlusChar(string $pattern): ?string
    {
        $close = self::delimitedBodyCloseAllowUtf($pattern);
        if ($close < 1) {
            return null;
        }
        $rawBody = \substr($pattern, 1, $close - 1);
        if (2 !== \strlen($rawBody)) {
            return null;
        }
        if ('+' !== \substr($rawBody, 1, 1)) {
            return null;
        }
        $ch = \substr($rawBody, 0, 1);
        if ('[' === $ch || '(' === $ch || ')' === $ch || '|' === $ch
            || '*' === $ch || '+' === $ch || '?' === $ch || '{' === $ch || '}' === $ch
            || '^' === $ch || '$' === $ch || '.' === $ch || '\\' === $ch) {
            return null;
        }

        return $ch;
    }

    /**
     * @return int 1 matched, 0 no match
     */
    private static function findLiteralCharPlus(string $ch, string $subject, int $offset): int
    {
        $subLen = \strlen($subject);
        $i = $offset;
        if ($i < 0) {
            $i = 0;
        }
        while ($i < $subLen) {
            if ($ch !== \substr($subject, $i, 1)) {
                ++$i;
                continue;
            }
            $j = $i + 1;
            while ($j < $subLen && $ch === \substr($subject, $j, 1)) {
                ++$j;
            }
            self::$lastReplacePos = $i;
            self::$lastReplaceBodyLen = $j - $i;

            return 1;
        }

        return 0;
    }

    /**
     * Parse `\x…` starting at $i (after the `x`). Sets {@see $hexEscapeNext}.
     *
     * @return string|null encoded bytes
     */
    private static function consumeHexEscapeAt(string $body, int $i, bool $utf): ?string
    {
        $blen = \strlen($body);
        if ($i < $blen && '{' === \substr($body, $i, 1)) {
            ++$i;
            $cp = 0;
            $digits = 0;
            while ($i < $blen && '}' !== \substr($body, $i, 1)) {
                $nibble = self::hexNibble(\substr($body, $i, 1));
                if ($nibble < 0) {
                    return null;
                }
                $cp = ($cp << 4) | $nibble;
                ++$i;
                ++$digits;
            }
            if ($i >= $blen || '}' !== \substr($body, $i, 1) || 0 === $digits) {
                return null;
            }
            ++$i;
            self::$hexEscapeNext = $i;
            if ($utf) {
                return self::encodeUtf8Codepoint($cp);
            }
            if ($cp > 0xFF) {
                return null;
            }

            return \chr($cp);
        }

        $cp = 0;
        $taken = 0;
        while ($taken < 2 && $i < $blen) {
            $nibble = self::hexNibble(\substr($body, $i, 1));
            if ($nibble < 0) {
                break;
            }
            $cp = ($cp << 4) | $nibble;
            ++$i;
            ++$taken;
        }
        self::$hexEscapeNext = $i;
        if (0 === $taken) {
            return "\0";
        }

        return \chr($cp);
    }

    /** NestedJIT-local UTF-8 encode (avoid VmPregUtf8 TU in thin AOT, #29024). */
    private static function encodeUtf8Codepoint(int $cp): ?string
    {
        if ($cp < 0 || $cp > 0x10FFFF) {
            return null;
        }
        if ($cp <= 0x7F) {
            return \chr($cp);
        }
        if ($cp <= 0x7FF) {
            return \chr(0xC0 | ($cp >> 6)).\chr(0x80 | ($cp & 0x3F));
        }
        if ($cp <= 0xFFFF) {
            return \chr(0xE0 | ($cp >> 12))
                .\chr(0x80 | (($cp >> 6) & 0x3F))
                .\chr(0x80 | ($cp & 0x3F));
        }

        return \chr(0xF0 | ($cp >> 18))
            .\chr(0x80 | (($cp >> 12) & 0x3F))
            .\chr(0x80 | (($cp >> 6) & 0x3F))
            .\chr(0x80 | ($cp & 0x3F));
    }

    /** @return int nibble 0–15, or -1 when not hex */
    private static function hexNibble(string $ch): int
    {
        if (1 !== \strlen($ch)) {
            return -1;
        }
        $o = \ord($ch);
        if ($o >= 48 && $o <= 57) {
            return $o - 48;
        }
        if ($o >= 65 && $o <= 70) {
            return $o - 55;
        }
        if ($o >= 97 && $o <= 102) {
            return $o - 87;
        }

        return -1;
    }

    /**
     * Index of closing `/` or `#` when it is the final character (no modifiers).
     * NestedJIT-safe substitute for strrpos (#27119 / peer #26888).
     */
    private static function delimitedBodyClose(string $pattern): int
    {
        $plen = \strlen($pattern);
        if ($plen < 2) {
            return -1;
        }
        $delim = \substr($pattern, 0, 1);
        if ('/' !== $delim && '#' !== $delim) {
            return -1;
        }
        if ($delim !== \substr($pattern, $plen - 1, 1)) {
            return -1;
        }

        return $plen - 1;
    }

    /** Char-by-char equality — NestedJIT strncmp/substr-prefix compares miscompile (#27119). */
    private static function literalEqualsAt(string $haystack, int $offset, string $needle, int $needleLen): bool
    {
        $i = 0;
        while ($i < $needleLen) {
            if (\substr($haystack, $offset + $i, 1) !== \substr($needle, $i, 1)) {
                return false;
            }
            ++$i;
        }

        return true;
    }

    private static int $lastReplacePos = -1;

    private static int $lastReplaceBodyLen = 0;

    public static function replaceOrEmpty(string $pattern, string $replacement, string $subject, int $limit): string
    {
        $kind = self::patternKind($pattern);
        if (0 === $kind || 8 === $kind || 9 === $kind) {
            return '';
        }
        if (1 === $kind) {
            return self::replaceLiteral($pattern, $replacement, $subject, $limit);
        }
        if ($kind >= 10 && $kind <= 15) {
            return self::replaceClassOnce($kind, $replacement, $subject, $limit);
        }

        return self::replaceClassPlus($kind, $replacement, $subject, $limit);
    }

    /**
     * Find next literal match — NestedJIT int-only (#27181).
     * Result offsets in {@see $lastReplacePos} / {@see $lastReplaceBodyLen}.
     *
     * @return int 1 matched, 0 no match, -1 unsupported
     */
    public static function replaceFindNext(string $pattern, string $subject, int $offset): int
    {
        self::$lastReplacePos = -1;
        self::$lastReplaceBodyLen = 0;
        $litPlus = self::literalCharPlusChar($pattern);
        if (null !== $litPlus) {
            return self::findLiteralCharPlus($litPlus, $subject, $offset);
        }
        $hexLit = self::exactHexEscapeLiteral($pattern);
        if (null !== $hexLit) {
            $bodyLen = \strlen($hexLit);
            $subLen = \strlen($subject);
            self::$lastReplaceBodyLen = $bodyLen;
            $i = $offset;
            if ($i < 0) {
                $i = 0;
            }
            while ($i + $bodyLen <= $subLen) {
                if (self::literalEqualsAt($subject, $i, $hexLit, $bodyLen)) {
                    self::$lastReplacePos = $i;

                    return 1;
                }
                ++$i;
            }

            return 0;
        }
        if (1 === self::isSlashXBraceFfUtfPatternInt($pattern)) {
            $subLen = \strlen($subject);
            $i = $offset;
            if ($i < 0) {
                $i = 0;
            }
            while ($i + 1 < $subLen) {
                if (0xC3 === \ord(\substr($subject, $i, 1)) && 0xBF === \ord(\substr($subject, $i + 1, 1))) {
                    self::$lastReplacePos = $i;
                    self::$lastReplaceBodyLen = 2;

                    return 1;
                }
                ++$i;
            }

            return 0;
        }
        $kind = self::patternKind($pattern);
        if (0 === $kind || 8 === $kind || 9 === $kind) {
            return -1;
        }
        if (1 !== $kind) {
            // Class-plus / single class: scan for first hit at/after offset.
            if ($kind >= 10 && $kind <= 15) {
                return self::findClassOnce($kind, $subject, $offset);
            }

            return self::findClassPlus($kind, $subject, $offset);
        }
        $close = self::delimitedBodyCloseAllowUtf($pattern);
        if ($close < 1) {
            return -1;
        }
        $rawBody = \substr($pattern, 1, $close - 1);
        $body = self::expandHexEscapesInBody($rawBody, self::patternHasUtfFlag($pattern));
        if (null === $body) {
            return -1;
        }
        $bodyLen = \strlen($body);
        $subLen = \strlen($subject);
        if (0 === $bodyLen) {
            return -1;
        }
        self::$lastReplaceBodyLen = $bodyLen;
        $i = $offset;
        if ($i < 0) {
            $i = 0;
        }
        while ($i + $bodyLen <= $subLen) {
            if (self::literalEqualsAt($subject, $i, $body, $bodyLen)) {
                self::$lastReplacePos = $i;

                return 1;
            }
            ++$i;
        }

        return 0;
    }

    public static function takeLastReplacePos(): int
    {
        return self::$lastReplacePos;
    }

    public static function takeLastReplaceBodyLen(): int
    {
        return self::$lastReplaceBodyLen;
    }

    private static function findClassPlus(int $kind, string $subject, int $offset): int
    {
        $charClass = 2;
        if (4 === $kind || 5 === $kind) {
            $charClass = 3;
        } elseif (6 === $kind || 7 === $kind) {
            $charClass = 4;
        }
        $subLen = \strlen($subject);
        $i = $offset;
        if ($i < 0) {
            $i = 0;
        }
        while ($i < $subLen) {
            if (self::charInClass(\substr($subject, $i, 1), $charClass)) {
                $j = $i + 1;
                while ($j < $subLen && self::charInClass(\substr($subject, $j, 1), $charClass)) {
                    ++$j;
                }
                self::$lastReplacePos = $i;
                self::$lastReplaceBodyLen = $j - $i;

                return 1;
            }
            ++$i;
        }

        return 0;
    }

    private static function clearCaps(): void
    {
        self::$cap0 = '';
        self::$cap1 = '';
        self::$cap2 = '';
        self::$cap3 = '';
        self::$cap4 = '';
        self::$cap5 = '';
        self::$cap6 = '';
        self::$cap7 = '';
        self::$capCount = 0;
        self::$capName1 = '';
    }

    private static function storeCapAt(int $index, string $value): void
    {
        if (0 === $index) {
            self::$cap0 = $value;
        } elseif (1 === $index) {
            self::$cap1 = $value;
        } elseif (2 === $index) {
            self::$cap2 = $value;
        } elseif (3 === $index) {
            self::$cap3 = $value;
        } elseif (4 === $index) {
            self::$cap4 = $value;
        } elseif (5 === $index) {
            self::$cap5 = $value;
        } elseif (6 === $index) {
            self::$cap6 = $value;
        } elseif (7 === $index) {
            self::$cap7 = $value;
        }
    }

    private static function storeCaps(string $full, bool $hasGroup): void
    {
        self::storeCapAt(0, $full);
        if ($hasGroup) {
            self::storeCapAt(1, $full);
            self::$capCount = 2;
            if ('' !== self::$kindName) {
                self::$capName1 = '' . self::$kindName;
            }
        } else {
            self::storeCapAt(1, '');
            self::$capCount = 1;
        }
    }

    /**
     * Literal prefix + `(literal|\d|\s|\w)` groups — NestedJIT-safe bool (#26888, #33611).
     *
     * Covers `/(a)(b)/`, `/b(c)/`, `/b(oundary)=(\w+)/`, and literal separators between groups.
     */
    private static function isLiteralGroupsPattern(string $pattern): bool
    {
        $plen = \strlen($pattern);
        if ($plen < 5) {
            return false;
        }
        $delim = \substr($pattern, 0, 1);
        if ('/' !== $delim && '#' !== $delim) {
            return false;
        }
        if ($delim !== \substr($pattern, $plen - 1, 1)) {
            return false;
        }
        $body = \substr($pattern, 1, $plen - 2);
        $blen = \strlen($body);
        if (0 === $blen) {
            return false;
        }
        $i = 0;
        while ($i < $blen && '(' !== \substr($body, $i, 1)) {
            if (!self::isLiteralBodyChar(\substr($body, $i, 1))) {
                return false;
            }
            ++$i;
        }
        if ($i >= $blen) {
            return false;
        }
        $groups = 0;
        while ($i < $blen) {
            if (!self::scanLiteralPrefixGroup($body, $i)) {
                return false;
            }
            ++$groups;
            if ($groups >= self::MAX_CAPS) {
                return false;
            }
            while ($i < $blen && '(' !== \substr($body, $i, 1)) {
                if (!self::isLiteralBodyChar(\substr($body, $i, 1))) {
                    return false;
                }
                ++$i;
            }
        }

        return $groups >= 1;
    }

    private static function isLiteralBodyChar(string $c): bool
    {
        return !('\\' === $c || '[' === $c || '(' === $c || ')' === $c || '|' === $c
            || '*' === $c || '+' === $c || '?' === $c || '{' === $c || '}' === $c
            || '^' === $c || '$' === $c || '.' === $c);
    }

    /** Advance $i past one `(...)` group; false when invalid. */
    private static function scanLiteralPrefixGroup(string $body, int &$i): bool
    {
        $blen = \strlen($body);
        if ($i >= $blen || '(' !== \substr($body, $i, 1)) {
            return false;
        }
        ++$i;
        if ($i + 1 < $blen && '\\' === \substr($body, $i, 1)) {
            $cls = \substr($body, $i + 1, 1);
            if ('d' !== $cls && 's' !== $cls && 'w' !== $cls) {
                return false;
            }
            $i += 2;
            if ($i < $blen && '+' === \substr($body, $i, 1)) {
                ++$i;
            }
            if ($i >= $blen || ')' !== \substr($body, $i, 1)) {
                return false;
            }
            ++$i;

            return true;
        }
        $start = $i;
        while ($i < $blen) {
            $c = \substr($body, $i, 1);
            if (')' === $c) {
                if ($i === $start) {
                    return false;
                }
                ++$i;

                return true;
            }
            if (!self::isLiteralBodyChar($c)) {
                return false;
            }
            ++$i;
        }

        return false;
    }

    /**
     * `/^literal/` / `#^literal#` — start-anchored literal body (#26825 RegexIterator MATCH).
     *
     * Body after `^` must be NestedJIT-safe literal (no other metacharacters).
     */
    private static function isAnchoredLiteralPrefixPattern(string $pattern): bool
    {
        $plen = \strlen($pattern);
        if ($plen < 4) {
            return false;
        }
        $delim = \substr($pattern, 0, 1);
        if ('/' !== $delim && '#' !== $delim) {
            return false;
        }
        if ($delim !== \substr($pattern, $plen - 1, 1)) {
            return false;
        }
        $body = \substr($pattern, 1, $plen - 2);
        if ('' === $body || '^' !== \substr($body, 0, 1)) {
            return false;
        }
        $lit = \substr($body, 1);
        $llen = \strlen($lit);
        if (0 === $llen) {
            return false;
        }
        $i = 0;
        while ($i < $llen) {
            $c = \substr($lit, $i, 1);
            if ('\\' === $c || '[' === $c || '(' === $c || ')' === $c || '|' === $c
                || '*' === $c || '+' === $c || '?' === $c || '{' === $c || '}' === $c
                || '^' === $c || '$' === $c || '.' === $c) {
                return false;
            }
            ++$i;
        }

        return true;
    }

    private static function matchAnchoredLiteralPrefix(string $pattern, string $subject, int $offset): int
    {
        $plen = \strlen($pattern);
        $lit = \substr($pattern, 2, $plen - 3);
        $litLen = \strlen($lit);
        $subLen = \strlen($subject);
        if ($offset > 0) {
            // ^ only matches at start of subject (not after offset) for thin AOT.
            return 0;
        }
        if ($litLen > $subLen) {
            return 0;
        }
        $i = 0;
        while ($i < $litLen) {
            if (\substr($subject, $i, 1) !== \substr($lit, $i, 1)) {
                return 0;
            }
            ++$i;
        }
        self::$cap0 = \substr($subject, 0, $litLen);
        self::$capCount = 1;

        return 1;
    }

    /**
     * Single literal capture — NestedJIT-safe (char scan + optional name) (#33887).
     */
    private static function matchExactSingleLiteralGroup(
        string $subject,
        int $offset,
        string $lit,
        string $name
    ): int {
        $litLen = \strlen($lit);
        $subLen = \strlen($subject);
        $j = $offset;
        while ($j + $litLen <= $subLen) {
            if (self::literalEqualsAt($subject, $j, $lit, $litLen)) {
                self::storeCapAt(0, $lit);
                self::storeCapAt(1, $lit);
                self::$capCount = 2;
                self::$capName1 = '' . $name;

                return 1;
            }
            if (0 === $litLen) {
                break;
            }
            ++$j;
        }

        return 0;
    }

    private static function matchExactAbGroups(string $subject, int $offset): int
    {
        $full = 'ab';
        $subLen = \strlen($subject);
        $j = $offset;
        while ($j + 2 <= $subLen) {
            if ('a' === \substr($subject, $j, 1) && 'b' === \substr($subject, $j + 1, 1)) {
                self::$cap0 = 'ab';
                self::$cap1 = 'a';
                self::$cap2 = 'b';
                self::$capCount = 3;

                return 1;
            }
            ++$j;
        }

        return 0;
    }

    /** `/a(b)/` — full match ab, group 1 is b (#33611). */
    private static function matchExactAGroupB(string $subject, int $offset): int
    {
        $subLen = \strlen($subject);
        $j = $offset;
        while ($j + 2 <= $subLen) {
            if ('a' === \substr($subject, $j, 1) && 'b' === \substr($subject, $j + 1, 1)) {
                self::$cap0 = 'ab';
                self::$cap1 = 'b';
                self::$capCount = 2;

                return 1;
            }
            ++$j;
        }

        return 0;
    }

    /** `/b(c)/` — full match bc, group 1 is c (#15642 tier-2 preg_capture). */
    private static function matchExactBGroupC(string $subject, int $offset): int
    {
        $subLen = \strlen($subject);
        $j = $offset;
        while ($j + 2 <= $subLen) {
            if ('b' === \substr($subject, $j, 1) && 'c' === \substr($subject, $j + 1, 1)) {
                self::$cap0 = 'bc';
                self::$cap1 = 'c';
                self::$capCount = 2;

                return 1;
            }
            ++$j;
        }

        return 0;
    }

    /** `/b(oundary)=(\w+)/` — multipart boundary capture (#15642 tier-2 preg_capture). */
    private static function matchExactBoundaryEqualsWord(string $subject, int $offset): int
    {
        $subLen = \strlen($subject);
        $j = $offset;
        while ($j < $subLen) {
            if ('b' !== \substr($subject, $j, 1)) {
                ++$j;
                continue;
            }
            $cursor = $j + 1;
            $boundary = 'oundary=';
            $bLen = 8;
            if ($cursor + $bLen > $subLen
                || !self::literalEqualsAt($subject, $cursor, $boundary, $bLen)) {
                ++$j;
                continue;
            }
            $cursor += $bLen;
            if ($cursor >= $subLen || !self::charInClass(\substr($subject, $cursor, 1), 4)) {
                ++$j;
                continue;
            }
            $wordStart = $cursor;
            ++$cursor;
            while ($cursor < $subLen && self::charInClass(\substr($subject, $cursor, 1), 4)) {
                ++$cursor;
            }
            self::$cap0 = \substr($subject, $j, $cursor - $j);
            self::$cap1 = 'oundary';
            self::$cap2 = \substr($subject, $wordStart, $cursor - $wordStart);
            self::$capCount = 3;

            return 1;
        }

        return 0;
    }

    /** `/a(b)(c)/` — full match abc, groups b and c (#33611). */
    private static function matchExactAGroupBC(string $subject, int $offset): int
    {
        $subLen = \strlen($subject);
        $j = $offset;
        while ($j + 3 <= $subLen) {
            if ('a' === \substr($subject, $j, 1)
                && 'b' === \substr($subject, $j + 1, 1)
                && 'c' === \substr($subject, $j + 2, 1)) {
                self::$cap0 = 'abc';
                self::$cap1 = 'b';
                self::$cap2 = 'c';
                self::$capCount = 3;

                return 1;
            }
            ++$j;
        }

        return 0;
    }

    /**
     * Literal prefix + capturing groups `/(x)/`, `/(a)(b)/`, `/b(c)/`, `/b(oundary)=(\w+)/` (#26888, #33611).
     */
    private static function matchLiteralGroups(string $pattern, string $subject, int $offset): int
    {
        $plen = \strlen($pattern);
        if ($plen < 5) {
            return -2;
        }
        $delim = \substr($pattern, 0, 1);
        if ('/' !== $delim && '#' !== $delim) {
            return -2;
        }
        if ($delim !== \substr($pattern, $plen - 1, 1)) {
            return -2;
        }
        $body = \substr($pattern, 1, $plen - 2);
        $blen = \strlen($body);
        if ($blen < 3) {
            return -2;
        }
        $pos = 0;
        $prefix = '';
        while ($pos < $blen && '(' !== \substr($body, $pos, 1)) {
            if (!self::isLiteralBodyChar(\substr($body, $pos, 1))) {
                return -2;
            }
            $prefix .= \substr($body, $pos, 1);
            ++$pos;
        }
        if ($pos >= $blen) {
            return -2;
        }
        $prefixLen = \strlen($prefix);
        $subLen = \strlen($subject);
        $j = $offset;
        while ($j < $subLen) {
            $cursor = $j;
            $parsePos = $pos;
            $matched = true;
            $capIndex = 1;
            if ($prefixLen > 0) {
                if ($cursor + $prefixLen > $subLen
                    || !self::literalEqualsAt($subject, $cursor, $prefix, $prefixLen)) {
                    ++$j;
                    continue;
                }
                $cursor += $prefixLen;
            }
            $matchStart = $j;
            while ($parsePos < $blen && $matched) {
                if ('(' !== \substr($body, $parsePos, 1)) {
                    $matched = false;
                    break;
                }
                ++$parsePos;
                if ($parsePos + 1 < $blen && '\\' === \substr($body, $parsePos, 1)) {
                    $cls = \substr($body, $parsePos + 1, 1);
                    $charClass = 0;
                    if ('d' === $cls) {
                        $charClass = 2;
                    } elseif ('s' === $cls) {
                        $charClass = 3;
                    } elseif ('w' === $cls) {
                        $charClass = 4;
                    } else {
                        $matched = false;
                        break;
                    }
                    $parsePos += 2;
                    $plus = false;
                    if ($parsePos < $blen && '+' === \substr($body, $parsePos, 1)) {
                        $plus = true;
                        ++$parsePos;
                    }
                    if ($parsePos >= $blen || ')' !== \substr($body, $parsePos, 1)) {
                        $matched = false;
                        break;
                    }
                    ++$parsePos;
                    if ($cursor >= $subLen
                        || !self::charInClass(\substr($subject, $cursor, 1), $charClass)) {
                        $matched = false;
                        break;
                    }
                    $capStart = $cursor;
                    ++$cursor;
                    if ($plus) {
                        while ($cursor < $subLen
                            && self::charInClass(\substr($subject, $cursor, 1), $charClass)) {
                            ++$cursor;
                        }
                    }
                    self::storeCapAt($capIndex, \substr($subject, $capStart, $cursor - $capStart));
                    ++$capIndex;
                } else {
                    $gStart = $parsePos;
                    while ($parsePos < $blen && ')' !== \substr($body, $parsePos, 1)) {
                        if (!self::isLiteralBodyChar(\substr($body, $parsePos, 1))) {
                            $matched = false;
                            break 2;
                        }
                        ++$parsePos;
                    }
                    if ($parsePos >= $blen || ')' !== \substr($body, $parsePos, 1)) {
                        $matched = false;
                        break;
                    }
                    $gContent = \substr($body, $gStart, $parsePos - $gStart);
                    ++$parsePos;
                    $gLen = \strlen($gContent);
                    if ($cursor + $gLen > $subLen
                        || !self::literalEqualsAt($subject, $cursor, $gContent, $gLen)) {
                        $matched = false;
                        break;
                    }
                    self::storeCapAt($capIndex, \substr($subject, $cursor, $gLen));
                    ++$capIndex;
                    $cursor += $gLen;
                }
                $sepStart = $parsePos;
                while ($parsePos < $blen && '(' !== \substr($body, $parsePos, 1)) {
                    if (!self::isLiteralBodyChar(\substr($body, $parsePos, 1))) {
                        $matched = false;
                        break 2;
                    }
                    ++$parsePos;
                }
                $sep = \substr($body, $sepStart, $parsePos - $sepStart);
                if ('' !== $sep) {
                    $sepLen = \strlen($sep);
                    if ($cursor + $sepLen > $subLen
                        || !self::literalEqualsAt($subject, $cursor, $sep, $sepLen)) {
                        $matched = false;
                        break;
                    }
                    $cursor += $sepLen;
                }
            }
            if ($matched && $parsePos === $blen) {
                self::storeCapAt(0, \substr($subject, $matchStart, $cursor - $matchStart));
                self::$capCount = $capIndex;

                return 1;
            }
            ++$j;
        }

        return 0;
    }

    private static function matchLiteral(string $pattern, string $subject, int $offset): int
    {
        $close = self::delimitedBodyCloseAllowUtf($pattern);
        if ($close < 1) {
            return -2;
        }
        $rawBody = \substr($pattern, 1, $close - 1);
        $body = self::expandHexEscapesInBody($rawBody, self::patternHasUtfFlag($pattern));
        if (null === $body) {
            return -2;
        }
        $bodyLen = \strlen($body);
        $subLen = \strlen($subject);
        if (0 === $bodyLen) {
            self::storeCaps('', false);

            return 1;
        }
        $i = $offset;
        while ($i + $bodyLen <= $subLen) {
            if (self::literalEqualsAt($subject, $i, $body, $bodyLen)) {
                self::storeCaps($body, false);

                return 1;
            }
            ++$i;
        }

        return 0;
    }

    private static function matchClassPlus(int $kind, string $subject, int $offset): int
    {
        $charClass = 2;
        if (4 === $kind || 5 === $kind) {
            $charClass = 3;
        } elseif (6 === $kind || 7 === $kind) {
            $charClass = 4;
        }
        $hasGroup = (3 === $kind || 5 === $kind || 7 === $kind);
        $subLen = \strlen($subject);
        $i = $offset;
        while ($i < $subLen) {
            if (self::charInClass(\substr($subject, $i, 1), $charClass)) {
                $j = $i + 1;
                while ($j < $subLen && self::charInClass(\substr($subject, $j, 1), $charClass)) {
                    ++$j;
                }
                self::storeCaps(\substr($subject, $i, $j - $i), $hasGroup);

                return 1;
            }
            ++$i;
        }

        return 0;
    }

    /** Single \d/\s/\w (kinds 10–15) — #27250. */
    private static function classKindToCharClass(int $kind): int
    {
        if (12 === $kind || 13 === $kind || 4 === $kind || 5 === $kind) {
            return 3;
        }
        if (14 === $kind || 15 === $kind || 6 === $kind || 7 === $kind) {
            return 4;
        }

        return 2;
    }

    private static function matchClassOnce(int $kind, string $subject, int $offset): int
    {
        $charClass = self::classKindToCharClass($kind);
        $hasGroup = (11 === $kind || 13 === $kind || 15 === $kind);
        $subLen = \strlen($subject);
        $i = $offset;
        while ($i < $subLen) {
            $ch = \substr($subject, $i, 1);
            if (self::charInClass($ch, $charClass)) {
                self::storeCaps($ch, $hasGroup);

                return 1;
            }
            ++$i;
        }

        return 0;
    }

    private static function findClassOnce(int $kind, string $subject, int $offset): int
    {
        $charClass = self::classKindToCharClass($kind);
        $subLen = \strlen($subject);
        $i = $offset;
        if ($i < 0) {
            $i = 0;
        }
        while ($i < $subLen) {
            if (self::charInClass(\substr($subject, $i, 1), $charClass)) {
                self::$lastReplacePos = $i;
                self::$lastReplaceBodyLen = 1;

                return 1;
            }
            ++$i;
        }

        return 0;
    }

    private static function replaceClassOnce(int $kind, string $replacement, string $subject, int $limit): string
    {
        $charClass = self::classKindToCharClass($kind);
        if (0 === $limit) {
            return $subject;
        }
        $out = '';
        $subLen = \strlen($subject);
        $cursor = 0;
        $n = 0;
        while ($cursor < $subLen) {
            if ($limit >= 0 && $n >= $limit) {
                $out .= \substr($subject, $cursor);

                return $out;
            }
            $ch = \substr($subject, $cursor, 1);
            if (!self::charInClass($ch, $charClass)) {
                $out .= $ch;
                ++$cursor;
                continue;
            }
            $out .= $replacement;
            ++$cursor;
            ++$n;
        }

        return $out;
    }

    private static function replaceLiteral(string $pattern, string $replacement, string $subject, int $limit): string
    {
        $close = self::delimitedBodyCloseAllowUtf($pattern);
        if ($close < 1) {
            return '';
        }
        $rawBody = \substr($pattern, 1, $close - 1);
        $body = self::expandHexEscapesInBody($rawBody, self::patternHasUtfFlag($pattern));
        if (null === $body) {
            return '';
        }
        if (0 === $limit) {
            return $subject;
        }
        $out = '';
        $subLen = \strlen($subject);
        $bodyLen = \strlen($body);
        $cursor = 0;
        $n = 0;
        while ($cursor < $subLen) {
            if ($limit >= 0 && $n >= $limit) {
                $out .= \substr($subject, $cursor);

                return $out;
            }
            if (0 === $bodyLen) {
                $out .= $replacement.\substr($subject, $cursor, 1);
                ++$cursor;
                ++$n;
                continue;
            }
            if ($cursor + $bodyLen <= $subLen
                && self::literalEqualsAt($subject, $cursor, $body, $bodyLen)) {
                $out .= $replacement;
                $cursor += $bodyLen;
                ++$n;
                continue;
            }
            $out .= \substr($subject, $cursor, 1);
            ++$cursor;
        }
        if (0 === $bodyLen) {
            $out .= $replacement;
        }

        return $out;
    }

    private static function replaceClassPlus(int $kind, string $replacement, string $subject, int $limit): string
    {
        $charClass = 2;
        if (4 === $kind || 5 === $kind) {
            $charClass = 3;
        } elseif (6 === $kind || 7 === $kind) {
            $charClass = 4;
        }
        if (0 === $limit) {
            return $subject;
        }
        $out = '';
        $subLen = \strlen($subject);
        $cursor = 0;
        $n = 0;
        while ($cursor < $subLen) {
            if ($limit >= 0 && $n >= $limit) {
                $out .= \substr($subject, $cursor);

                return $out;
            }
            if (!self::charInClass(\substr($subject, $cursor, 1), $charClass)) {
                $out .= \substr($subject, $cursor, 1);
                ++$cursor;
                continue;
            }
            $j = $cursor + 1;
            while ($j < $subLen && self::charInClass(\substr($subject, $j, 1), $charClass)) {
                ++$j;
            }
            $out .= $replacement;
            $cursor = $j;
            ++$n;
        }

        return $out;
    }

    private static function charInClass(string $ch, int $charClass): bool
    {
        if (1 !== \strlen($ch)) {
            return false;
        }
        $o = \ord($ch);
        if (2 === $charClass) {
            return $o >= 48 && $o <= 57;
        }
        if (3 === $charClass) {
            return 32 === $o || 9 === $o || 10 === $o || 13 === $o || 12 === $o || 11 === $o;
        }

        return ($o >= 48 && $o <= 57) || ($o >= 65 && $o <= 90) || ($o >= 97 && $o <= 122) || 95 === $o;
    }

    private static int $splitCount = 0;

    private static string $split0 = '';

    private static string $split1 = '';

    private static string $split2 = '';

    private static string $split3 = '';

    private static string $split4 = '';

    private static string $split5 = '';

    private static string $split6 = '';

    private static string $split7 = '';

    private static int $matchAllCount = 0;

    private static string $matchAll0 = '';

    private static string $matchAll1 = '';

    private static string $matchAll2 = '';

    private static string $matchAll3 = '';

    private static string $matchAll4 = '';

    private static string $matchAll5 = '';

    private static string $matchAll6 = '';

    private static string $matchAll7 = '';

    private static int $matchAllGroupCount = 1;
    private static string $matchAllG1_0 = '';
    private static string $matchAllG1_1 = '';
    private static string $matchAllG1_2 = '';
    private static string $matchAllG1_3 = '';
    private static string $matchAllG1_4 = '';
    private static string $matchAllG1_5 = '';
    private static string $matchAllG1_6 = '';
    private static string $matchAllG1_7 = '';
    private static string $matchAllG2_0 = '';
    private static string $matchAllG2_1 = '';
    private static string $matchAllG2_2 = '';
    private static string $matchAllG2_3 = '';
    private static string $matchAllG2_4 = '';
    private static string $matchAllG2_5 = '';
    private static string $matchAllG2_6 = '';
    private static string $matchAllG2_7 = '';
    private static string $matchAllG3_0 = '';
    private static string $matchAllG3_1 = '';
    private static string $matchAllG3_2 = '';
    private static string $matchAllG3_3 = '';
    private static string $matchAllG3_4 = '';
    private static string $matchAllG3_5 = '';
    private static string $matchAllG3_6 = '';
    private static string $matchAllG3_7 = '';

    /**
     * NestedJIT-safe preg_match_all — int return + PREG_PATTERN_ORDER rows (#27195 / #34994).
     *
     * flags==0 only. Stores group rows in slots; LLVM builds `$matches[g] = [m0, …]`
     * via {@see self::matchAllGroupMatch} (groups 0..3, matches 0..7).
     *
     * @return int match count, 0 if none, -1 unsupported/error
     */
    public static function matchAllStore(string $pattern, string $subject, int $flags, int $offset): int
    {
        self::$matchAllCount = 0;
        self::$matchAllGroupCount = 1;
        self::$matchAll0 = '';
        self::$matchAll1 = '';
        self::$matchAll2 = '';
        self::$matchAll3 = '';
        self::$matchAll4 = '';
        self::$matchAll5 = '';
        self::$matchAll6 = '';
        self::$matchAll7 = '';
        self::$matchAllG1_0 = '';
        self::$matchAllG1_1 = '';
        self::$matchAllG1_2 = '';
        self::$matchAllG1_3 = '';
        self::$matchAllG1_4 = '';
        self::$matchAllG1_5 = '';
        self::$matchAllG1_6 = '';
        self::$matchAllG1_7 = '';
        self::$matchAllG2_0 = '';
        self::$matchAllG2_1 = '';
        self::$matchAllG2_2 = '';
        self::$matchAllG2_3 = '';
        self::$matchAllG2_4 = '';
        self::$matchAllG2_5 = '';
        self::$matchAllG2_6 = '';
        self::$matchAllG2_7 = '';
        self::$matchAllG3_0 = '';
        self::$matchAllG3_1 = '';
        self::$matchAllG3_2 = '';
        self::$matchAllG3_3 = '';
        self::$matchAllG3_4 = '';
        self::$matchAllG3_5 = '';
        self::$matchAllG3_6 = '';
        self::$matchAllG3_7 = '';
        if (0 !== $flags) {
            return -1;
        }
        $subLen = \strlen($subject);
        if ($offset < 0 || $offset > $subLen) {
            return 0;
        }
        $kind = self::patternKind($pattern);
        // Literals (1), class-plus (2/4/6), single class (10/12/14), grouped (+1) and
        // exact literal-groups (8). (#34994 expands groups beyond #27195 full-match-only.)
        if (1 !== $kind && 2 !== $kind && 3 !== $kind && 4 !== $kind && 5 !== $kind
            && 6 !== $kind && 7 !== $kind && 8 !== $kind
            && 10 !== $kind && 11 !== $kind && 12 !== $kind && 13 !== $kind
            && 14 !== $kind && 15 !== $kind) {
            return -1;
        }
        // Reuse NestedJIT-proven matchCount; advance past each hit (#27195).
        $cursor = $offset;
        $n = 0;
        while ($cursor < $subLen) {
            $rc = self::matchCount($pattern, $subject, $cursor);
            if ($rc < 0) {
                return -1;
            }
            if (0 === $rc) {
                break;
            }
            self::syncCaptureGroupCapsAfterMatch($pattern);
            $full = '' . self::lastCap(0);
            $flen = \strlen($full);
            if ($flen < 1) {
                return -1;
            }
            $start = $cursor;
            $found = 0;
            while ($start + $flen <= $subLen) {
                if (self::literalEqualsAt($subject, $start, $full, $flen)) {
                    $found = 1;
                    break;
                }
                ++$start;
            }
            if (0 === $found) {
                return -1;
            }
            if ($n >= self::MAX_CAPS) {
                return -1;
            }
            $gc = self::lastCapCount();
            if ($gc < 1) {
                $gc = 1;
            }
            // Thin slots hold groups 0..3 only.
            if ($gc > 4) {
                return -1;
            }
            if (0 === $n) {
                self::$matchAllGroupCount = $gc;
            }
            // Unrolled one-arg group stores — NestedJIT scrambles (group,match) pairs (#34994).
            self::storeMatchAllAt($n, '' . self::lastCap(0));
            if ($gc > 1) {
                self::storeMatchAllG1At($n, '' . self::lastCap(1));
            }
            if ($gc > 2) {
                self::storeMatchAllG2At($n, '' . self::lastCap(2));
            }
            if ($gc > 3) {
                self::storeMatchAllG3At($n, '' . self::lastCap(3));
            }
            ++$n;
            $cursor = $start + $flen;
        }
        self::$matchAllCount = $n;

        return $n;
    }

    public static function matchAllPartCount(): int
    {
        return self::$matchAllCount;
    }

    public static function matchAllPart(int $index): string
    {
        if (0 === $index) {
            return '' . self::$matchAll0;
        }
        if (1 === $index) {
            return '' . self::$matchAll1;
        }
        if (2 === $index) {
            return '' . self::$matchAll2;
        }
        if (3 === $index) {
            return '' . self::$matchAll3;
        }
        if (4 === $index) {
            return '' . self::$matchAll4;
        }
        if (5 === $index) {
            return '' . self::$matchAll5;
        }
        if (6 === $index) {
            return '' . self::$matchAll6;
        }
        if (7 === $index) {
            return '' . self::$matchAll7;
        }

        return '';
    }

    private static function storeMatchAllAt(int $index, string $value): void
    {
        if (0 === $index) {
            self::$matchAll0 = $value;
        } elseif (1 === $index) {
            self::$matchAll1 = $value;
        } elseif (2 === $index) {
            self::$matchAll2 = $value;
        } elseif (3 === $index) {
            self::$matchAll3 = $value;
        } elseif (4 === $index) {
            self::$matchAll4 = $value;
        } elseif (5 === $index) {
            self::$matchAll5 = $value;
        } elseif (6 === $index) {
            self::$matchAll6 = $value;
        } elseif (7 === $index) {
            self::$matchAll7 = $value;
        }
    }

    public static function matchAllGroupCount(): int
    {
        return self::$matchAllGroupCount;
    }

    /** NestedJIT-safe group columns (#34994) — one-arg readers. */
    public static function matchAllG1Part(int $match): string
    {
        if (0 === $match) {
            return '' . self::$matchAllG1_0;
        } elseif (1 === $match) {
            return '' . self::$matchAllG1_1;
        } elseif (2 === $match) {
            return '' . self::$matchAllG1_2;
        } elseif (3 === $match) {
            return '' . self::$matchAllG1_3;
        } elseif (4 === $match) {
            return '' . self::$matchAllG1_4;
        } elseif (5 === $match) {
            return '' . self::$matchAllG1_5;
        } elseif (6 === $match) {
            return '' . self::$matchAllG1_6;
        } elseif (7 === $match) {
            return '' . self::$matchAllG1_7;
        }

        return '';
    }

    public static function matchAllG2Part(int $match): string
    {
        if (0 === $match) {
            return '' . self::$matchAllG2_0;
        } elseif (1 === $match) {
            return '' . self::$matchAllG2_1;
        } elseif (2 === $match) {
            return '' . self::$matchAllG2_2;
        } elseif (3 === $match) {
            return '' . self::$matchAllG2_3;
        } elseif (4 === $match) {
            return '' . self::$matchAllG2_4;
        } elseif (5 === $match) {
            return '' . self::$matchAllG2_5;
        } elseif (6 === $match) {
            return '' . self::$matchAllG2_6;
        } elseif (7 === $match) {
            return '' . self::$matchAllG2_7;
        }

        return '';
    }

    public static function matchAllG3Part(int $match): string
    {
        if (0 === $match) {
            return '' . self::$matchAllG3_0;
        } elseif (1 === $match) {
            return '' . self::$matchAllG3_1;
        } elseif (2 === $match) {
            return '' . self::$matchAllG3_2;
        } elseif (3 === $match) {
            return '' . self::$matchAllG3_3;
        } elseif (4 === $match) {
            return '' . self::$matchAllG3_4;
        } elseif (5 === $match) {
            return '' . self::$matchAllG3_5;
        } elseif (6 === $match) {
            return '' . self::$matchAllG3_6;
        } elseif (7 === $match) {
            return '' . self::$matchAllG3_7;
        }

        return '';
    }

    /** PREG_PATTERN_ORDER cell — host/unit tests; AOT uses one-arg G*Part (#34994). */
    public static function matchAllGroupMatch(int $group, int $match): string
    {
        if (0 === $group) {
            return self::matchAllPart($match);
        }
        if (1 === $group) {
            return self::matchAllG1Part($match);
        }
        if (2 === $group) {
            return self::matchAllG2Part($match);
        }
        if (3 === $group) {
            return self::matchAllG3Part($match);
        }

        return '';
    }

    private static function storeMatchAllG1At(int $index, string $value): void
    {
        if (0 === $index) {
            self::$matchAllG1_0 = $value;
        } elseif (1 === $index) {
            self::$matchAllG1_1 = $value;
        } elseif (2 === $index) {
            self::$matchAllG1_2 = $value;
        } elseif (3 === $index) {
            self::$matchAllG1_3 = $value;
        } elseif (4 === $index) {
            self::$matchAllG1_4 = $value;
        } elseif (5 === $index) {
            self::$matchAllG1_5 = $value;
        } elseif (6 === $index) {
            self::$matchAllG1_6 = $value;
        } elseif (7 === $index) {
            self::$matchAllG1_7 = $value;
        }
    }

    private static function storeMatchAllG2At(int $index, string $value): void
    {
        if (0 === $index) {
            self::$matchAllG2_0 = $value;
        } elseif (1 === $index) {
            self::$matchAllG2_1 = $value;
        } elseif (2 === $index) {
            self::$matchAllG2_2 = $value;
        } elseif (3 === $index) {
            self::$matchAllG2_3 = $value;
        } elseif (4 === $index) {
            self::$matchAllG2_4 = $value;
        } elseif (5 === $index) {
            self::$matchAllG2_5 = $value;
        } elseif (6 === $index) {
            self::$matchAllG2_6 = $value;
        } elseif (7 === $index) {
            self::$matchAllG2_7 = $value;
        }
    }

    private static function storeMatchAllG3At(int $index, string $value): void
    {
        if (0 === $index) {
            self::$matchAllG3_0 = $value;
        } elseif (1 === $index) {
            self::$matchAllG3_1 = $value;
        } elseif (2 === $index) {
            self::$matchAllG3_2 = $value;
        } elseif (3 === $index) {
            self::$matchAllG3_3 = $value;
        } elseif (4 === $index) {
            self::$matchAllG3_4 = $value;
        } elseif (5 === $index) {
            self::$matchAllG3_5 = $value;
        } elseif (6 === $index) {
            self::$matchAllG3_6 = $value;
        } elseif (7 === $index) {
            self::$matchAllG3_7 = $value;
        }
    }

    /**
     * NestedJIT-safe preg_split — int return + static string slots only (no PHP arrays, #27080).
     *
     * Thin standalone AOT no longer calls this — LLVM uses replaceFindNext + subject slices
     * (#27208). Kept for host unit tests / ThinAot splitArgv stub parity.
     *
     * @return int part count, or -1 on unsupported/error
     */
    public static function splitStore(string $pattern, string $subject, int $limit, int $flags): int
    {
        self::$splitCount = 0;
        self::$split0 = '';
        self::$split1 = '';
        self::$split2 = '';
        self::$split3 = '';
        self::$split4 = '';
        self::$split5 = '';
        self::$split6 = '';
        self::$split7 = '';
        if (0 !== $flags) {
            return -1;
        }
        $kind = self::patternKind($pattern);
        if (0 === $kind || 8 === $kind || 9 === $kind || 1 === $kind) {
            return -1;
        }
        $charClass = 2;
        if (4 === $kind || 5 === $kind) {
            $charClass = 3;
        } elseif (6 === $kind || 7 === $kind) {
            $charClass = 4;
        }
        $subLen = \strlen($subject);
        $cursor = 0;
        $n = 0;
        while ($cursor < $subLen) {
            while ($cursor < $subLen && self::charInClass(\substr($subject, $cursor, 1), $charClass)) {
                ++$cursor;
            }
            if ($cursor >= $subLen) {
                break;
            }
            if ($limit > 0 && $n + 1 >= $limit) {
                if ($n >= self::MAX_CAPS) {
                    return -1;
                }
                self::storeSplitAt($n, '' . \substr($subject, $cursor));
                ++$n;
                self::$splitCount = $n;

                return $n;
            }
            $start = $cursor;
            while ($cursor < $subLen) {
                if (self::charInClass(\substr($subject, $cursor, 1), $charClass)) {
                    break;
                }
                ++$cursor;
            }
            if ($n >= self::MAX_CAPS) {
                return -1;
            }
            self::storeSplitAt($n, '' . \substr($subject, $start, $cursor - $start));
            ++$n;
        }
        self::$splitCount = $n;

        return $n;
    }

    public static function splitPartCount(): int
    {
        return self::$splitCount;
    }

    public static function splitPart(int $index): string
    {
        if (0 === $index) {
            return '' . self::$split0;
        }
        if (1 === $index) {
            return '' . self::$split1;
        }
        if (2 === $index) {
            return '' . self::$split2;
        }
        if (3 === $index) {
            return '' . self::$split3;
        }
        if (4 === $index) {
            return '' . self::$split4;
        }
        if (5 === $index) {
            return '' . self::$split5;
        }
        if (6 === $index) {
            return '' . self::$split6;
        }
        if (7 === $index) {
            return '' . self::$split7;
        }

        return '';
    }

    private static function storeSplitAt(int $index, string $value): void
    {
        if (0 === $index) {
            self::$split0 = $value;
        } elseif (1 === $index) {
            self::$split1 = $value;
        } elseif (2 === $index) {
            self::$split2 = $value;
        } elseif (3 === $index) {
            self::$split3 = $value;
        } elseif (4 === $index) {
            self::$split4 = $value;
        } elseif (5 === $index) {
            self::$split5 = $value;
        } elseif (6 === $index) {
            self::$split6 = $value;
        } elseif (7 === $index) {
            self::$split7 = $value;
        }
    }
}
