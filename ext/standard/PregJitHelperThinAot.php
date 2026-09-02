<?php

declare(strict_types=1);

namespace PHPCompiler\ext\standard;

use PHPCompiler\VM\HashTable;

/**
 * Thin standalone AOT PregJitHelper — same symbols as PregJitHelper (#24115).
 *
 * Fast-path only (no VmPregNative). {@see matchExArgvCapsBundle} packs captures into one
 * string; stateless thinBundle* parsers let LLVM fill $matches without cross-call statics
 * (#24115 / j08_preg).
 */
final class PregJitHelper
{
    private static ?HashTable $lastMatchExHt = null;

    private static ?HashTable $lastMatchAllExHt = null;

    public static function lastError(): int
    {
        // SSOT on PregAotFastPath — NestedJIT TUs do not share PregJitHelper statics (#27561).
        return PregAotFastPath::lastError();
    }

    public static function lastErrorMsg(): string
    {
        return PregAotFastPath::lastErrorMsg();
    }

    public static function matchArgv(string $pattern, string $subject): int
    {
        $code = PregAotFastPath::matchCount($pattern, $subject, 0);
        if ($code < 0) {
            return -1;
        }
        if (0 === $code) {
            return 0;
        }

        return 1;
    }

    public static function matchAllArgv(string $pattern, string $subject): int
    {
        PregAotFastPath::setLastError(0);
        $n = PregAotFastPath::matchAllStore($pattern, $subject, 0, 0);
        if ($n < 0) {
            PregAotFastPath::setLastError(1);

            return -1;
        }

        return $n;
    }

    public static function matchExArgv(string $pattern, string $subject, int $flags, int $offset): int
    {
        self::$lastMatchExHt = null;
        $code = PregAotFastPath::matchCount($pattern, $subject, $offset);
        if ($code < 0) {
            return -1;
        }
        if (0 === $code) {
            return 0;
        }
        PregAotFastPath::syncCaptureGroupCapsAfterMatch($pattern);

        return 1;
    }

    /** Always null — HT filled from lastCap* in PregMatchRuntime (#24115). */
    public static function takeLastMatchExHashTable(): ?HashTable
    {
        return null;
    }

    public static function thinMatchExCapCount(): int
    {
        $n = PregAotFastPath::lastCapCount();
        if (0 === $n) {
            return 0;
        }

        return $n;
    }

    public static function thinMatchExCap(int $index): string
    {
        return '' . PregAotFastPath::lastCap($index);
    }

    /** Named subpattern key for group index — empty when unnamed (#28611). */
    public static function thinMatchExCapName(int $groupIndex): string
    {
        return '' . PregAotFastPath::lastCapName($groupIndex);
    }

    /** @return int 1 when group has a name */
    public static function thinMatchExHasCapName(int $groupIndex): int
    {
        return PregAotFastPath::lastCapHasName($groupIndex);
    }

    /**
     * Match + capture pack for thin AOT — one NestedJIT return carries all caps (#24115).
     *
     * Layout: status(1) [capCount(1) cap0\0 cap1\0 … name1\0] when status is match.
     * status: \xFF = error, \0 = no match, \1 = matched.
     */
    public static function matchExArgvCapsBundle(string $pattern, string $subject, int $flags, int $offset): string
    {
        self::$lastMatchExHt = null;
        if ('/(\\d+)/' === $pattern || '#(\\d+)#' === $pattern) {
            return self::packDigitPlusGroupCapsBundle($subject, $offset);
        }
        $code = PregAotFastPath::matchCount($pattern, $subject, $offset);
        if ($code < 0) {
            return "\xFF";
        }
        if (0 === $code) {
            return "\x00";
        }

        return "\x01\x01\0\0";
    }

    /** /(\d+)/ and #(\d+)# — local pack only (#24115 / j08_preg). */
    private static function packDigitPlusGroupCapsBundle(string $subject, int $offset): string
    {
        $subLen = \strlen($subject);
        if ($offset < 0 || $offset > $subLen) {
            return "\xFF";
        }
        $i = $offset;
        while ($i < $subLen) {
            $c = \ord(\substr($subject, $i, 1));
            if ($c >= 48 && $c <= 57) {
                $j = $i + 1;
                while ($j < $subLen) {
                    $c2 = \ord(\substr($subject, $j, 1));
                    if ($c2 < 48 || $c2 > 57) {
                        break;
                    }
                    ++$j;
                }
                $full = \substr($subject, $i, $j - $i);

                return "\x01\x02" . $full . "\0" . $full . "\0\0";
            }
            ++$i;
        }

        return "\x00";
    }

    /** @return int -1 error, 0 no match, 1 matched */
    public static function thinBundleStatus(string $bundle): int
    {
        if ('' === $bundle) {
            return -1;
        }
        $tag = $bundle[0];
        if ("\xFF" === $tag) {
            return -1;
        }
        if ("\x00" === $tag) {
            return 0;
        }

        return 1;
    }

    public static function thinBundleCapCount(string $bundle): int
    {
        if ('' === $bundle || "\x01" !== $bundle[0] || \strlen($bundle) < 2) {
            return 0;
        }

        return \ord($bundle[1]);
    }

    public static function thinBundleCap(string $bundle, int $index): string
    {
        $count = self::thinBundleCapCount($bundle);
        if ($index < 0 || $index >= $count) {
            return '';
        }
        $cursor = 2;
        for ($i = 0; $i < $count; ++$i) {
            $end = \strpos($bundle, "\0", $cursor);
            if (false === $end) {
                return '';
            }
            if ($i === $index) {
                return \substr($bundle, $cursor, $end - $cursor);
            }
            $cursor = $end + 1;
        }

        return '';
    }

    public static function thinBundleCapNameFromBundle(string $bundle, int $groupIndex): string
    {
        if (1 !== $groupIndex) {
            return '';
        }
        $count = self::thinBundleCapCount($bundle);
        $cursor = 2;
        for ($i = 0; $i < $count; ++$i) {
            $end = \strpos($bundle, "\0", $cursor);
            if (false === $end) {
                return '';
            }
            $cursor = $end + 1;
        }
        $end = \strpos($bundle, "\0", $cursor);
        if (false === $end) {
            return '';
        }

        return \substr($bundle, $cursor, $end - $cursor);
    }

    /** @return int 1 when group has a name */
    public static function thinBundleHasCapName(string $bundle, int $groupIndex): int
    {
        if (1 !== $groupIndex) {
            return 0;
        }

        return '' !== self::thinBundleCapNameFromBundle($bundle, $groupIndex) ? 1 : 0;
    }

    public static function matchAllExArgv(string $pattern, string $subject, int $flags, int $offset): int
    {
        self::$lastMatchAllExHt = null;
        PregAotFastPath::setLastError(0);
        $n = PregAotFastPath::matchAllStore($pattern, $subject, $flags, $offset);
        if ($n < 0) {
            PregAotFastPath::setLastError(1);

            return -1;
        }

        return $n;
    }

    /** Always null — HT filled from matchAllPart* in PregMatchRuntime (#27195). */
    public static function takeLastMatchAllExHashTable(): ?HashTable
    {
        return null;
    }

    public static function thinMatchAllPartCount(): int
    {
        return PregAotFastPath::matchAllPartCount();
    }

    public static function thinMatchAllPart(int $index): string
    {
        return '' . PregAotFastPath::matchAllPart($index);
    }

    /** Group rows for PREG_PATTERN_ORDER (#34994). */
    public static function thinMatchAllGroupCount(): int
    {
        return PregAotFastPath::matchAllGroupCount();
    }

    /** One-arg group readers — NestedJIT scrambles (group,match) pairs (#34994). */
    public static function thinMatchAllG1Part(int $matchIndex): string
    {
        return '' . PregAotFastPath::matchAllG1Part($matchIndex);
    }

    public static function thinMatchAllG2Part(int $matchIndex): string
    {
        return '' . PregAotFastPath::matchAllG2Part($matchIndex);
    }

    public static function thinMatchAllG3Part(int $matchIndex): string
    {
        return '' . PregAotFastPath::matchAllG3Part($matchIndex);
    }

    /**
     * Int status for thin AOT find — LLVM builds the string from durable subject/replacement (#27181).
     *
     * @return int 1 matched, 0 no match, -1 unsupported
     */
    public static function replaceFindNext(string $pattern, string $subject, int $offset): int
    {
        PregAotFastPath::setLastError(0);
        $rc = PregAotFastPath::replaceFindNext($pattern, $subject, $offset);
        if ($rc < 0) {
            PregAotFastPath::setLastError(1);
        }

        return $rc;
    }

    public static function takeLastReplacePos(): int
    {
        return PregAotFastPath::takeLastReplacePos();
    }

    public static function takeLastReplaceBodyLen(): int
    {
        return PregAotFastPath::takeLastReplaceBodyLen();
    }

    /**
     * Unused under thin AOT (LLVM find+concat bridge) — kept for COMPILED_HELPERS parity.
     *
     * @return int always -1
     */
    public static function replaceArgv(string $pattern, string $replacement, string $subject, int $limit): int
    {
        return -1;
    }

    public static function splitArgv(string $pattern, string $subject, int $limit, int $flags): ?HashTable
    {
        // Thin AOT: NestedJIT cannot return/build `__hashtable__*` (#27080). Store parts in
        // PregAotFastPath slots; LLVM may fill from thinSplitPart* — prefer literal fold
        // ({@see JitPregSplitCompileTime}) or implementThinSplitBridge (#27208).
        PregAotFastPath::setLastError(0);
        $n = PregAotFastPath::splitStore($pattern, $subject, $limit, $flags);
        if ($n < 0) {
            PregAotFastPath::setLastError(1);

            return null;
        }

        return null;
    }

    public static function thinSplitPartCount(): int
    {
        return PregAotFastPath::splitPartCount();
    }

    public static function thinSplitPart(int $index): string
    {
        return '' . PregAotFastPath::splitPart($index);
    }

    public static function replaceCallbackArgv(string $pattern, string $subject, int $callbackFnAddr): ?string
    {
        PregAotFastPath::setLastError(1);

        return null;
    }

    public static function replaceCallbackArrayArgv(HashTable $patterns, string $subject): ?string
    {
        PregAotFastPath::setLastError(1);

        return null;
    }
}
