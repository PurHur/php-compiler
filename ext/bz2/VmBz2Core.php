<?php

declare(strict_types=1);

namespace PHPCompiler\ext\bz2;

/**
 * Pure PHP bzip2 (compressjs / micro-bunzip port; php-src ext/bz2/bz2.c behavior reference).
 *
 * Sole bzip2 codec for VM/JIT/AOT when libbz2 FFI is unavailable (#8868).
 */
final class VmBz2Core
{
    private const MAX_HUFCODE_BITS = 20;

    private const MAX_SYMBOLS = 258;

    private const SYMBOL_RUNA = 0;

    private const SYMBOL_RUNB = 1;

    private const MIN_GROUPS = 2;

    private const MAX_GROUPS = 6;

    private const GROUP_SIZE = 50;

    private const WHOLEPI = 0x314159265359;

    private const SQRTPI = 0x177245385090;

    public static function available(): bool
    {
        return true;
    }

    public static function compress(string $source, int $blockSize100k = 4, int $workFactor = 0): string|false
    {
        if ($blockSize100k < 1 || $blockSize100k > 9) {
            return false;
        }
        if ($workFactor < 0 || $workFactor > 250) {
            return false;
        }

        return self::compressFile($source, $blockSize100k);
    }

    public static function decompress(string $source, int $small = 0): string|false
    {
        if ($small < 0 || $small > 1) {
            return false;
        }
        if ('' === $source) {
            return '';
        }

        try {
            return self::decode($source);
        } catch (\Throwable) {
            return false;
        }
    }

    private static function compressFile(string $input, int $blockSizeMultiplier): string
    {
        $blockSize = $blockSizeMultiplier * 100000 - 19;
        $writer = new Bz2BitWriter();
        $writer->writeByte(0x42);
        $writer->writeByte(0x5A);
        $writer->writeByte(0x68);
        $writer->writeByte(0x30 + $blockSizeMultiplier);

        $streamCrc = 0;
        $offset = 0;
        $block = \array_fill(0, $blockSize, 0);

        do {
            $crc = new Bz2Crc32();
            $length = self::readBlock($input, $offset, $block, $blockSize, $crc);
            if ($length > 0) {
                $streamCrc = (($streamCrc << 1) | (($streamCrc >> 31) & 1)) ^ $crc->getCrc();
                $streamCrc &= 0xFFFFFFFF;
                $writer->writeBits(48, self::WHOLEPI);
                $writer->writeBits(32, $crc->getCrc());
                self::compressBlock($block, $length, $writer);
            }
        } while ($length === $blockSize);

        $writer->writeBits(48, self::SQRTPI);
        $writer->writeBits(32, $streamCrc);
        $writer->flush();

        return $writer->toString();
    }

    /**
     * @param list<int> $block
     */
    private static function readBlock(string $input, int &$offset, array &$block, int $maxLen, Bz2Crc32 $crc): int
    {
        $pos = 0;
        $lastChar = -1;
        $runLength = 0;
        $inputLen = \strlen($input);

        while ($pos < $maxLen) {
            if (4 === $runLength) {
                $block[$pos++] = 0;
                if ($pos >= $maxLen) {
                    break;
                }
            }
            if ($offset >= $inputLen) {
                break;
            }
            $ch = \ord($input[$offset++]);
            $crc->update($ch);
            if ($ch !== $lastChar) {
                $lastChar = $ch;
                $runLength = 1;
            } else {
                ++$runLength;
                if ($runLength > 4) {
                    if ($runLength < 256) {
                        ++$block[$pos - 1];
                        continue;
                    }
                    $runLength = 1;
                }
            }
            $block[$pos++] = $ch;
        }

        return $pos;
    }

    /**
     * @param list<int> $block
     */
    private static function compressBlock(array $block, int $length, Bz2BitWriter $out): void
    {
        $U = \array_fill(0, $length, 0);
        $pidx = self::bwtransform2($block, $U, $length, 256);
        $out->writeBit(0);
        $out->writeBits(24, $pidx);

        $used = \array_fill(0, 256, false);
        $compact = \array_fill(0, 16, false);
        for ($i = 0; $i < $length; ++$i) {
            $c = $block[$i];
            $used[$c] = true;
            $compact[$c >> 4] = true;
        }
        for ($i = 0; $i < 16; ++$i) {
            $out->writeBit((int) $compact[$i]);
        }
        for ($i = 0; $i < 16; ++$i) {
            if ($compact[$i]) {
                for ($j = 0; $j < 16; ++$j) {
                    $out->writeBit((int) $used[($i << 4) | $j]);
                }
            }
        }

        $alphabetSize = 0;
        for ($i = 0; $i < 256; ++$i) {
            if ($used[$i]) {
                ++$alphabetSize;
            }
        }

        $endOfBlock = $alphabetSize + 1;
        $A = [];
        $freq = \array_fill(0, $endOfBlock + 1, 0);
        $M = [];
        for ($i = 0, $j = 0; $i < 256; ++$i) {
            if ($used[$i]) {
                $M[$j++] = $i;
            }
        }

        $pos = 0;
        $runLength = 0;
        $emit = static function (int $c) use (&$A, &$freq, &$pos): void {
            $A[$pos++] = $c;
            ++$freq[$c];
        };
        $emitLastRun = static function () use (&$runLength, $emit): void {
            while (0 !== $runLength) {
                if ($runLength & 1) {
                    $emit(0);
                    --$runLength;
                } else {
                    $emit(1);
                    $runLength -= 2;
                }
                $runLength >>= 1;
            }
        };

        for ($i = 0; $i < $length; ++$i) {
            $c = $U[$i];
            for ($j = 0; $j < $alphabetSize; ++$j) {
                if ($M[$j] === $c) {
                    break;
                }
            }
            self::mtf($M, $j);
            if (0 === $j) {
                ++$runLength;
            } else {
                $emitLastRun();
                $emit($j + 1);
                $runLength = 0;
            }
        }
        $emitLastRun();
        $emit($endOfBlock);

        if ($pos >= 2400) {
            $targetGroups = 6;
        } elseif ($pos >= 1200) {
            $targetGroups = 5;
        } elseif ($pos >= 600) {
            $targetGroups = 4;
        } elseif ($pos >= 200) {
            $targetGroups = 3;
        } else {
            $targetGroups = 2;
        }

        $groups = [new Bz2StaticHuffman($freq, $endOfBlock + 1)];
        $flatFreq = \array_fill(0, $endOfBlock + 1, 1);
        $groups[] = new Bz2StaticHuffman($flatFreq, $endOfBlock + 1);
        $selectors = \array_fill(0, (int) \ceil($pos / self::GROUP_SIZE), 0);
        self::optimizeHuffmanGroups($groups, $targetGroups, $A, $selectors, $endOfBlock + 1);
        self::assignSelectors($selectors, $groups, $A);

        $out->writeBits(3, \count($groups));
        $out->writeBits(15, \count($selectors));
        for ($i = 0; $i < \count($groups); ++$i) {
            $M[$i] = $i;
        }
        foreach ($selectors as $s) {
            for ($j = 0; $j < \count($groups); ++$j) {
                if ($M[$j] === $s) {
                    break;
                }
            }
            self::mtf($M, $j);
            while ($j > 0) {
                $out->writeBit(1);
                --$j;
            }
            $out->writeBit(0);
        }
        foreach ($groups as $group) {
            $group->emit($out);
            $group->computeCanonical();
        }
        for ($i = 0, $k = 0; $i < $pos; ) {
            $huff = $groups[$selectors[$k++]];
            for ($j = 0; $j < self::GROUP_SIZE && $i < $pos; ++$j) {
                $huff->encode($out, $A[$i++]);
            }
        }
    }

    /**
     * @param list<int> $array
     */
    private static function mtf(array &$array, int $index): int
    {
        $src = $array[$index];
        for ($i = $index; $i > 0; --$i) {
            $array[$i] = $array[$i - 1];
        }
        $array[0] = $src;

        return $src;
    }

    /**
     * @param list<int> $selectors
     * @param list<Bz2StaticHuffman> $groups
     * @param list<int> $input
     */
    private static function assignSelectors(array &$selectors, array $groups, array $input): void
    {
        $k = 0;
        for ($i = 0, $len = \count($input); $i < $len; $i += self::GROUP_SIZE) {
            $groupSize = \min(self::GROUP_SIZE, $len - $i);
            $best = 0;
            $bestCost = $groups[0]->cost($input, $i, $groupSize);
            for ($j = 1, $gLen = \count($groups); $j < $gLen; ++$j) {
                $groupCost = $groups[$j]->cost($input, $i, $groupSize);
                if ($groupCost < $bestCost) {
                    $best = $j;
                    $bestCost = $groupCost;
                }
            }
            $selectors[$k++] = $best;
        }
    }

    /**
     * @param list<Bz2StaticHuffman|null> $groups
     * @param list<int> $selectors
     * @param list<int> $input
     */
    private static function optimizeHuffmanGroups(array &$groups, int $targetGroups, array $input, array &$selectors, int $alphabetSize): void
    {
        while (\count($groups) < $targetGroups) {
            self::assignSelectors($selectors, $groups, $input);
            $groupCounts = \array_fill(0, \count($groups), 0);
            foreach ($selectors as $sel) {
                ++$groupCounts[$sel];
            }
            $which = 0;
            $maxCount = $groupCounts[0];
            foreach ($groupCounts as $idx => $count) {
                if ($count > $maxCount) {
                    $maxCount = $count;
                    $which = $idx;
                }
            }
            $splits = [];
            foreach ($selectors as $idx => $sel) {
                if ($sel !== $which) {
                    continue;
                }
                $start = $idx * self::GROUP_SIZE;
                $end = \min($start + self::GROUP_SIZE, \count($input));
                $splits[] = ['index' => $idx, 'cost' => $groups[$which]->cost($input, $start, $end - $start)];
            }
            \usort($splits, static fn (array $a, array $b): int => $a['cost'] <=> $b['cost']);
            $half = \count($splits) >> 1;
            for ($i = $half, $sLen = \count($splits); $i < $sLen; ++$i) {
                $selectors[$splits[$i]['index']] = \count($groups);
            }
            $groups[] = null;
            $freq = [];
            for ($i = 0; $i < \count($groups); ++$i) {
                $freq[$i] = \array_fill(0, $alphabetSize, 0);
            }
            for ($i = 0, $j = 0; $i < \count($input); ) {
                $f = $freq[$selectors[$j++]];
                for ($k = 0; $k < self::GROUP_SIZE && $i < \count($input); ++$k) {
                    ++$f[$input[$i++]];
                }
            }
            for ($i = 0; $i < \count($groups); ++$i) {
                $groups[$i] = new Bz2StaticHuffman($freq[$i], $alphabetSize);
            }
        }
    }

    /**
     * @param list<int> $block
     * @param list<int> $U
     */
    private static function bwtransform2(array $block, array &$U, int $n, int $alphabetSize): int
    {
        if ($n <= 1) {
            if (1 === $n) {
                $U[0] = $block[0];
            }

            return 0;
        }

        $TT = \array_fill(0, $n * 2, 0);
        for ($i = 0; $i < $n; ++$i) {
            $TT[$i] = $block[$i];
            $TT[$n + $i] = $block[$i];
        }
        $A = \array_fill(0, $n * 2, 0);
        self::saIs($TT, $A, 0, $n * 2, $alphabetSize, false, 0, 0);
        $pidx = 0;
        for ($i = 0, $j = 0; $i < 2 * $n; ++$i) {
            $s = $A[$i];
            if ($s < $n) {
                if (0 === $s) {
                    $pidx = $j;
                }
                if (--$s < 0) {
                    $s = $n - 1;
                }
                $U[$j++] = $block[$s];
            }
        }

        return $pidx;
    }

    /**
     * @param list<int> $T
     * @param list<int> $SA
     */
    private static function saIs(array $T, array &$SA, int $fs, int $n, int $k, bool $isbwt, int $tOffset = 0, int $saOffset = 0): int
    {
        $C = \array_fill(0, $k, 0);
        $B = \array_fill(0, $k, 0);

        self::getCounts($T, $C, $tOffset, $n, $k);
        self::getBuckets($C, $B, $k, true);
        for ($i = 0; $i < $n; ++$i) {
            $SA[$saOffset + $i] = 0;
        }
        $b = -1;
        $i = $n - 1;
        $j = $n;
        $m = 0;
        $c0 = $T[$tOffset + $n - 1];
        do {
            $c1 = $c0;
        } while ((--$i >= 0) && (($c0 = $T[$tOffset + $i]) >= $c1));
        for (; $i >= 0; ) {
            do {
                $c1 = $c0;
            } while ((--$i >= 0) && (($c0 = $T[$tOffset + $i]) <= $c1));
            if ($i >= 0) {
                if ($b >= 0) {
                    $SA[$saOffset + $b] = $j;
                }
                $b = --$B[$c1];
                $j = $i;
                ++$m;
                do {
                    $c1 = $c0;
                } while ((--$i >= 0) && (($c0 = $T[$tOffset + $i]) >= $c1));
            }
        }

        if ($m > 1) {
            self::lmsSort($T, $SA, $C, $B, $tOffset, $saOffset, $n, $k);
            $name = self::lmsPostproc($T, $SA, $tOffset, $saOffset, $n, $m);
        } elseif (1 === $m) {
            $SA[$saOffset + $b] = $j + 1;
            $name = 1;
        } else {
            $name = 0;
        }

        if ($name < $m) {
            $newfs = ($n + $fs) - ($m * 2);
            $raOffset = $saOffset + $m + $newfs;
            for ($i = $saOffset + $m + ($n >> 1) - 1, $j = $saOffset + $m * 2 + $newfs - 1; $m <= $i; --$i) {
                if (0 !== $SA[$i]) {
                    $SA[$j--] = $SA[$i] - 1;
                }
            }
            self::saIs($SA, $SA, $newfs, $m, $name, false, $raOffset, $saOffset);
            $i = $n - 1;
            $j = $saOffset + $m * 2 - 1;
            $c0 = $T[$tOffset + $n - 1];
            do {
                $c1 = $c0;
            } while ((--$i >= 0) && (($c0 = $T[$tOffset + $i]) >= $c1));
            for (; $i >= 0; ) {
                do {
                    $c1 = $c0;
                } while ((--$i >= 0) && (($c0 = $T[$tOffset + $i]) <= $c1));
                if ($i >= 0) {
                    $SA[$j--] = $i + 1;
                    do {
                        $c1 = $c0;
                    } while ((--$i >= 0) && (($c0 = $T[$tOffset + $i]) >= $c1));
                }
            }
            for ($i = 0; $i < $m; ++$i) {
                $SA[$saOffset + $i] = $SA[$saOffset + $m + $SA[$saOffset + $i]];
            }
        }

        if ($m > 1) {
            self::getCounts($T, $C, $tOffset, $n, $k);
            self::getBuckets($C, $B, $k, true);
            $i = $m - 1;
            $j = $n;
            $p = $SA[$saOffset + $m - 1];
            $c1 = $T[$tOffset + $p];
            do {
                $q = $B[$c0 = $c1];
                while ($q < $j) {
                    $SA[$saOffset + (--$j)] = 0;
                }
                do {
                    $SA[$saOffset + (--$j)] = $p;
                    if (--$i < 0) {
                        break 2;
                    }
                    $p = $SA[$saOffset + $i];
                } while (($c1 = $T[$tOffset + $p]) === $c0);
            } while ($i >= 0);
            while ($j > 0) {
                $SA[$saOffset + (--$j)] = 0;
            }
        }

        if ($isbwt) {
            return self::computeBwt($T, $SA, $C, $B, $tOffset, $saOffset, $n, $k);
        }
        self::induceSa($T, $SA, $C, $B, $tOffset, $saOffset, $n, $k);

        return 0;
    }

    /**
     * @param list<int> $T
     * @param list<int> $C
     */
    private static function getCounts(array $T, array &$C, int $tOffset, int $n, int $k): void
    {
        for ($i = 0; $i < $k; ++$i) {
            $C[$i] = 0;
        }
        for ($i = 0; $i < $n; ++$i) {
            ++$C[$T[$tOffset + $i]];
        }
    }

    /**
     * @param list<int> $C
     * @param list<int> $B
     */
    private static function getBuckets(array $C, array &$B, int $k, bool $end): void
    {
        $sum = 0;
        if ($end) {
            for ($i = 0; $i < $k; ++$i) {
                $sum += $C[$i];
                $B[$i] = $sum;
            }
        } else {
            for ($i = 0; $i < $k; ++$i) {
                $sum += $C[$i];
                $B[$i] = $sum - $C[$i];
            }
        }
    }

    /**
     * @param list<int> $T
     * @param list<int> $SA
     * @param list<int> $C
     * @param list<int> $B
     */
    private static function lmsSort(array $T, array &$SA, array &$C, array &$B, int $tOffset, int $saOffset, int $n, int $k): void
    {
        self::getCounts($T, $C, $tOffset, $n, $k);
        self::getBuckets($C, $B, $k, false);
        $j = $n - 1;
        $c1 = $T[$tOffset + $j];
        $b = $B[$c1];
        --$j;
        $SA[$saOffset + $b++] = ($T[$tOffset + $j] < $c1) ? ~$j : $j;
        for ($i = 0; $i < $n; ++$i) {
            if (($j = $SA[$saOffset + $i]) > 0) {
                if (($c0 = $T[$tOffset + $j]) !== $c1) {
                    $B[$c1] = $b;
                    $b = $B[$c1 = $c0];
                }
                --$j;
                $SA[$saOffset + $b++] = ($T[$tOffset + $j] < $c1) ? ~$j : $j;
                $SA[$saOffset + $i] = 0;
            } elseif ($j < 0) {
                $SA[$saOffset + $i] = ~$j;
            }
        }
        self::getCounts($T, $C, $tOffset, $n, $k);
        self::getBuckets($C, $B, $k, true);
        for ($i = $n - 1, $b = $B[$c1 = 0]; $i >= 0; --$i) {
            if (($j = $SA[$saOffset + $i]) > 0) {
                if (($c0 = $T[$tOffset + $j]) !== $c1) {
                    $B[$c1] = $b;
                    $b = $B[$c1 = $c0];
                }
                --$j;
                $SA[$saOffset + (--$b)] = ($T[$tOffset + $j] > $c1) ? ~($j + 1) : $j;
                $SA[$saOffset + $i] = 0;
            }
        }
    }

    /**
     * @param list<int> $T
     * @param list<int> $SA
     */
    private static function lmsPostproc(array $T, array &$SA, int $tOffset, int $saOffset, int $n, int $m): int
    {
        for ($i = 0; ($p = $SA[$saOffset + $i]) < 0; ++$i) {
            $SA[$saOffset + $i] = ~$p;
        }
        if ($i < $m) {
            for ($j = $i, ++$i; ; ++$i) {
                if (($p = $SA[$saOffset + $i]) < 0) {
                    $SA[$saOffset + $j++] = ~$p;
                    $SA[$saOffset + $i] = 0;
                    if ($j === $m) {
                        break;
                    }
                }
            }
        }
        $c0 = $T[$tOffset + ($i = $j = $n - 1)];
        do {
            $c1 = $c0;
        } while ((--$i >= 0) && (($c0 = $T[$tOffset + $i]) >= $c1));
        for (; $i >= 0; ) {
            do {
                $c1 = $c0;
            } while ((--$i >= 0) && (($c0 = $T[$tOffset + $i]) <= $c1));
            if ($i >= 0) {
                $SA[$saOffset + $m + (($i + 1) >> 1)] = $j - $i;
                $j = $i + 1;
                do {
                    $c1 = $c0;
                } while ((--$i >= 0) && (($c0 = $T[$tOffset + $i]) >= $c1));
            }
        }
        $name = 0;
        $q = $n;
        $qlen = 0;
        for ($i = 0; $i < $m; ++$i) {
            $p = $SA[$saOffset + $i];
            $plen = $SA[$saOffset + $m + ($p >> 1)];
            $diff = true;
            if ($plen === $qlen && ($q + $plen) < $n) {
                for ($j = 0; $j < $plen && $T[$tOffset + $p + $j] === $T[$tOffset + $q + $j]; ++$j) {
                }
                if ($j === $plen) {
                    $diff = false;
                }
            }
            if ($diff) {
                ++$name;
                $q = $p;
                $qlen = $plen;
            }
            $SA[$saOffset + $m + ($p >> 1)] = $name;
        }

        return $name;
    }

    /**
     * @param list<int> $T
     * @param list<int> $SA
     * @param list<int> $C
     * @param list<int> $B
     */
    private static function induceSa(array $T, array &$SA, array &$C, array &$B, int $tOffset, int $saOffset, int $n, int $k): void
    {
        self::getCounts($T, $C, $tOffset, $n, $k);
        self::getBuckets($C, $B, $k, false);
        $j = $n - 1;
        $c1 = $T[$tOffset + $j];
        $b = $B[$c1];
        $SA[$saOffset + $b++] = (($j > 0) && ($T[$tOffset + $j - 1] < $c1)) ? ~$j : $j;
        for ($i = 0; $i < $n; ++$i) {
            $j = $SA[$saOffset + $i];
            $SA[$saOffset + $i] = ~$j;
            if ($j > 0) {
                --$j;
                if (($c0 = $T[$tOffset + $j]) !== $c1) {
                    $B[$c1] = $b;
                    $b = $B[$c1 = $c0];
                }
                $SA[$saOffset + $b++] = (($j > 0) && ($T[$tOffset + $j - 1] < $c1)) ? ~$j : $j;
            }
        }
        self::getCounts($T, $C, $tOffset, $n, $k);
        self::getBuckets($C, $B, $k, true);
        for ($i = $n - 1, $b = $B[$c1 = 0]; $i >= 0; --$i) {
            if (($j = $SA[$saOffset + $i]) > 0) {
                --$j;
                if (($c0 = $T[$tOffset + $j]) !== $c1) {
                    $B[$c1] = $b;
                    $b = $B[$c1 = $c0];
                }
                $SA[$saOffset + (--$b)] = (($j === 0) || ($T[$tOffset + $j - 1] > $c1)) ? ~$j : $j;
            } else {
                $SA[$saOffset + $i] = ~$j;
            }
        }
    }

    /**
     * @param list<int> $T
     * @param list<int> $SA
     * @param list<int> $C
     * @param list<int> $B
     */
    private static function computeBwt(array $T, array &$SA, array &$C, array &$B, int $tOffset, int $saOffset, int $n, int $k): int
    {
        $pidx = -1;
        self::getCounts($T, $C, $tOffset, $n, $k);
        self::getBuckets($C, $B, $k, false);
        $j = $n - 1;
        $c1 = $T[$tOffset + $j];
        $b = $B[$c1];
        $SA[$saOffset + $b++] = (($j > 0) && ($T[$tOffset + $j - 1] < $c1)) ? ~$j : $j;
        for ($i = 0; $i < $n; ++$i) {
            if (($j = $SA[$saOffset + $i]) > 0) {
                --$j;
                $SA[$saOffset + $i] = ~(($c0 = $T[$tOffset + $j]));
                if ($c0 !== $c1) {
                    $B[$c1] = $b;
                    $b = $B[$c1 = $c0];
                }
                $SA[$saOffset + $b++] = (($j > 0) && ($T[$tOffset + $j - 1] < $c1)) ? ~$j : $j;
            } elseif (0 !== $j) {
                $SA[$saOffset + $i] = ~$j;
            }
        }
        self::getCounts($T, $C, $tOffset, $n, $k);
        self::getBuckets($C, $B, $k, true);
        for ($i = $n - 1, $b = $B[$c1 = 0]; $i >= 0; --$i) {
            if (($j = $SA[$saOffset + $i]) > 0) {
                --$j;
                $SA[$saOffset + $i] = $c0 = $T[$tOffset + $j];
                if ($c0 !== $c1) {
                    $B[$c1] = $b;
                    $b = $B[$c1 = $c0];
                }
                $SA[$saOffset + (--$b)] = (($j > 0) && ($T[$tOffset + $j - 1] > $c1)) ? (~$T[$tOffset + $j - 1]) : $j;
            } elseif (0 !== $j) {
                $SA[$saOffset + $i] = ~$j;
            } else {
                $pidx = $i;
            }
        }

        return $pidx;
    }

    private static function decode(string $input): string
    {
        if (\strlen($input) < 4 || 'BZh' !== \substr($input, 0, 3)) {
            throw new \RuntimeException('Not bzip data');
        }
        $level = \ord($input[3]) - 0x30;
        if ($level < 1 || $level > 9) {
            throw new \RuntimeException('level out of range');
        }

        $reader = new Bz2BitReader($input, 4);
        $dbufSize = 100000 * $level;
        $streamCrc = 0;
        $output = [];

        while (true) {
            $h = $reader->readBits(48);
            if ($h === self::SQRTPI) {
                $targetStreamCrc = $reader->readBits(32);
                if ($targetStreamCrc !== $streamCrc) {
                    throw new \RuntimeException('Bad stream CRC');
                }
                break;
            }
            if ($h !== self::WHOLEPI) {
                throw new \RuntimeException('Not bzip data');
            }
            $targetBlockCrc = $reader->readBits(32);
            $streamCrc = ($targetBlockCrc ^ ((($streamCrc << 1) | (($streamCrc >> 31) & 1)) & 0xFFFFFFFF)) & 0xFFFFFFFF;
            if ($reader->readBits(1)) {
                throw new \RuntimeException('Obsolete bzip format');
            }
            $origPointer = $reader->readBits(24);
            if ($origPointer > $dbufSize) {
                throw new \RuntimeException('initial position out of bounds');
            }
            $t = $reader->readBits(16);
            $symToByte = [];
            $symTotal = 0;
            for ($i = 0; $i < 16; ++$i) {
                if ($t & (1 << (0xF - $i))) {
                    $o = $i * 16;
                    $k = $reader->readBits(16);
                    for ($j = 0; $j < 16; ++$j) {
                        if ($k & (1 << (0xF - $j))) {
                            $symToByte[$symTotal++] = $o + $j;
                        }
                    }
                }
            }
            $groupCount = $reader->readBits(3);
            if ($groupCount < self::MIN_GROUPS || $groupCount > self::MAX_GROUPS) {
                throw new \RuntimeException('Data error');
            }
            $nSelectors = $reader->readBits(15);
            if (0 === $nSelectors) {
                throw new \RuntimeException('Data error');
            }
            $mtfSymbol = \range(0, 255);
            $selectors = [];
            for ($i = 0; $i < $nSelectors; ++$i) {
                for ($j = 0; $reader->readBits(1); ++$j) {
                    if ($j >= $groupCount) {
                        throw new \RuntimeException('Data error');
                    }
                }
                $selectors[$i] = self::mtf($mtfSymbol, $j);
            }
            $symCount = $symTotal + 2;
            $groups = [];
            for ($gj = 0; $gj < $groupCount; ++$gj) {
                $length = \array_fill(0, $symCount, 0);
                $temp = \array_fill(0, self::MAX_HUFCODE_BITS + 1, 0);
                $t = $reader->readBits(5);
                for ($i = 0; $i < $symCount; ++$i) {
                    while (true) {
                        if ($t < 1 || $t > self::MAX_HUFCODE_BITS) {
                            throw new \RuntimeException('Data error');
                        }
                        if (!$reader->readBits(1)) {
                            break;
                        }
                        if (!$reader->readBits(1)) {
                            ++$t;
                        } else {
                            --$t;
                        }
                    }
                    $length[$i] = $t;
                }
                $minLen = $maxLen = $length[0];
                for ($i = 1; $i < $symCount; ++$i) {
                    if ($length[$i] > $maxLen) {
                        $maxLen = $length[$i];
                    } elseif ($length[$i] < $minLen) {
                        $minLen = $length[$i];
                    }
                }
                $hufGroup = [
                    'permute' => \array_fill(0, self::MAX_SYMBOLS, 0),
                    'limit' => \array_fill(0, self::MAX_HUFCODE_BITS + 2, 0),
                    'base' => \array_fill(0, self::MAX_HUFCODE_BITS + 1, 0),
                    'minLen' => $minLen,
                    'maxLen' => $maxLen,
                ];
                $pp = 0;
                for ($i = $minLen; $i <= $maxLen; ++$i) {
                    $temp[$i] = $hufGroup['limit'][$i] = 0;
                    for ($ti = 0; $ti < $symCount; ++$ti) {
                        if ($length[$ti] === $i) {
                            $hufGroup['permute'][$pp++] = $ti;
                        }
                    }
                }
                for ($i = 0; $i < $symCount; ++$i) {
                    ++$temp[$length[$i]];
                }
                $pp = $t = 0;
                for ($i = $minLen; $i < $maxLen; ++$i) {
                    $pp += $temp[$i];
                    $hufGroup['limit'][$i] = $pp - 1;
                    $pp <<= 1;
                    $t += $temp[$i];
                    $hufGroup['base'][$i + 1] = $pp - $t;
                }
                $hufGroup['limit'][$maxLen + 1] = 0x7FFFFFFF;
                $hufGroup['limit'][$maxLen] = $pp + $temp[$maxLen] - 1;
                $hufGroup['base'][$minLen] = 0;
                $groups[] = $hufGroup;
            }

            $byteCount = \array_fill(0, 256, 0);
            for ($i = 0; $i < 256; ++$i) {
                $mtfSymbol[$i] = $i;
            }
            $runPos = 0;
            $dbufCount = 0;
            $selector = 0;
            $symCountLoop = 0;
            $dbuf = \array_fill(0, $dbufSize, 0);
            $t = 0;
            $uc = 0;
            while (true) {
                if (!($symCountLoop--)) {
                    $symCountLoop = self::GROUP_SIZE - 1;
                    if ($selector >= $nSelectors) {
                        throw new \RuntimeException('Data error');
                    }
                    $hufGroup = $groups[$selectors[$selector++]];
                }
                $i = $hufGroup['minLen'];
                $j = $reader->readBits($i);
                while (true) {
                    if ($i > $hufGroup['maxLen']) {
                        throw new \RuntimeException('Data error');
                    }
                    if ($j <= $hufGroup['limit'][$i]) {
                        break;
                    }
                    $j = ($j << 1) | $reader->readBits(1);
                    ++$i;
                }
                $j -= $hufGroup['base'][$i];
                if ($j < 0 || $j >= self::MAX_SYMBOLS) {
                    throw new \RuntimeException('Data error');
                }
                $nextSym = $hufGroup['permute'][$j];
                if ($nextSym === self::SYMBOL_RUNA || $nextSym === self::SYMBOL_RUNB) {
                    if (!$runPos) {
                        $runPos = 1;
                        $t = 0;
                    }
                    if ($nextSym === self::SYMBOL_RUNA) {
                        $t += $runPos;
                    } else {
                        $t += 2 * $runPos;
                    }
                    $runPos <<= 1;
                    continue;
                }
                if ($runPos) {
                    $runPos = 0;
                    if ($dbufCount + $t > $dbufSize) {
                        throw new \RuntimeException('Data error');
                    }
                    $uc = $symToByte[$mtfSymbol[0]];
                    $byteCount[$uc] += $t;
                    while ($t-- > 0) {
                        $dbuf[$dbufCount++] = $uc;
                    }
                }
                if ($nextSym > $symTotal) {
                    break;
                }
                if ($dbufCount >= $dbufSize) {
                    throw new \RuntimeException('Data error');
                }
                $i = $nextSym - 1;
                $uc = self::mtf($mtfSymbol, $i);
                $uc = $symToByte[$uc];
                ++$byteCount[$uc];
                $dbuf[$dbufCount++] = $uc;
            }

            if ($origPointer < 0 || $origPointer >= $dbufCount) {
                throw new \RuntimeException('Data error');
            }

            $rleBlock = self::inverseBwt(\array_slice($dbuf, 0, $dbufCount), $origPointer);
            $blockCrc = new Bz2Crc32();
            self::inverseRle1($rleBlock, $blockCrc, $output);
            if ($blockCrc->getCrc() !== $targetBlockCrc) {
                throw new \RuntimeException('Bad block CRC');
            }
        }

        if ([] === $output) {
            return '';
        }

        return \pack('C*', ...$output);
    }

    /**
     * @param list<int> $buf
     *
     * @return list<int>
     */
    private static function inverseBwt(array $buf, int $ptr): array
    {
        $n = \count($buf);
        if (0 === $n) {
            return [];
        }
        $cumm = \array_fill(0, 256, 0);
        foreach ($buf as $v) {
            ++$cumm[$v];
        }
        $sum = 0;
        for ($i = 0; $i < 256; ++$i) {
            $t = $sum;
            $sum += $cumm[$i];
            $cumm[$i] = $t;
        }
        $perm = \array_fill(0, $n, 0);
        foreach ($buf as $i => $b) {
            $perm[$cumm[$b]] = $i;
            ++$cumm[$b];
        }
        $out = \array_fill(0, $n, 0);
        $i = $perm[$ptr];
        for ($j = 0; $j < $n; ++$j) {
            $out[$j] = $buf[$i];
            $i = $perm[$i];
        }

        return $out;
    }

    /**
     * @param list<int> $buf
     * @param list<int> $output
     */
    private static function inverseRle1(array $buf, Bz2Crc32 $crc, array &$output): void
    {
        $idx = 0;
        $n = \count($buf);
        $lastVal = -1;
        $lastCnt = 0;
        while (true) {
            if (-4 === $lastCnt) {
                if ($idx >= $n) {
                    throw new \RuntimeException('Data error');
                }
                $lastCnt = $buf[$idx++];
                if ($lastCnt > 0) {
                    // emit one byte below
                } else {
                    $lastCnt = 0;
                    if ($idx >= $n) {
                        return;
                    }
                    $b = $buf[$idx++];
                    if ($b !== $lastVal) {
                        $lastCnt = 0;
                        $lastVal = $b;
                    }
                }
            } elseif ($lastCnt <= 0) {
                if ($idx >= $n) {
                    return;
                }
                $b = $buf[$idx++];
                if ($b !== $lastVal) {
                    $lastCnt = 0;
                    $lastVal = $b;
                }
            }
            $output[] = $lastVal;
            $crc->update($lastVal);
            --$lastCnt;
        }
    }
}

final class Bz2Crc32
{
    private int $crc = 0xFFFFFFFF;

    public function update(int $value): void
    {
        $this->crc = (($this->crc << 8) ^ VmBz2CoreLookup::crc32Lookup((($this->crc >> 24) ^ $value) & 0xFF)) & 0xFFFFFFFF;
    }

    public function updateRun(int $value, int $count): void
    {
        while ($count-- > 0) {
            $this->update($value);
        }
    }

    public function getCrc(): int
    {
        return (~$this->crc) & 0xFFFFFFFF;
    }
}

/** @internal */
final class VmBz2CoreLookup
{
    /** @var list<int> */
    private static array $table = [
        0x00000000, 0x04c11db7, 0x09823b6e, 0x0d4326d9, 0x130476dc, 0x17c56b6b, 0x1a864db2, 0x1e475005,
        0x2608edb8, 0x22c9f00f, 0x2f8ad6d6, 0x2b4bcb61, 0x350c9b64, 0x31cd86d3, 0x3c8ea00a, 0x384fbdbd,
        0x4c11db70, 0x48d0c6c7, 0x4593e01e, 0x4152fda9, 0x5f15adac, 0x5bd4b01b, 0x569796c2, 0x52568b75,
        0x6a1936c8, 0x6ed82b7f, 0x639b0da6, 0x675a1011, 0x791d4014, 0x7ddc5da3, 0x709f7b7a, 0x745e66cd,
        0x9823b6e0, 0x9ce2ab57, 0x91a18d8e, 0x95609039, 0x8b27c03c, 0x8fe6dd8b, 0x82a5fb52, 0x8664e6e5,
        0xbe2b5b58, 0xbaea46ef, 0xb7a96036, 0xb3687d81, 0xad2f2d84, 0xa9ee3033, 0xa4ad16ea, 0xa06c0b5d,
        0xd4326d90, 0xd0f37027, 0xddb056fe, 0xd9714b49, 0xc7361b4c, 0xc3f706fb, 0xceb42022, 0xca753d95,
        0xf23a8028, 0xf6fb9d9f, 0xfbb8bb46, 0xff79a6f1, 0xe13ef6f4, 0xe5ffeb43, 0xe8bccd9a, 0xec7dd02d,
        0x34867077, 0x30476dc0, 0x3d044b19, 0x39c556ae, 0x278206ab, 0x23431b1c, 0x2e003dc5, 0x2ac12072,
        0x128e9dcf, 0x164f8078, 0x1b0ca6a1, 0x1fcdbb16, 0x018aeb13, 0x054bf6a4, 0x0808d07d, 0x0cc9cdca,
        0x7897ab07, 0x7c56b6b0, 0x71159069, 0x75d48dde, 0x6b93dddb, 0x6f52c06c, 0x6211e6b5, 0x66d0fb02,
        0x5e9f46bf, 0x5a5e5b08, 0x571d7dd1, 0x53dc6066, 0x4d9b3063, 0x495a2dd4, 0x44190b0d, 0x40d816ba,
        0xaca5c697, 0xa864db20, 0xa527fdf9, 0xa1e6e04e, 0xbfa1b04b, 0xbb60adfc, 0xb6238b25, 0xb2e29692,
        0x8aad2b2f, 0x8e6c3698, 0x832f1041, 0x87ee0df6, 0x99a95df3, 0x9d684044, 0x902b669d, 0x94ea7b2a,
        0xe0b41de7, 0xe4750050, 0xe9362689, 0xedf73b3e, 0xf3b06b3b, 0xf771768c, 0xfa325055, 0xfef34de2,
        0xc6bcf05f, 0xc27dede8, 0xcf3ecb31, 0xcbffd686, 0xd5b88683, 0xd1799b34, 0xdc3abded, 0xd8fba05a,
        0x690ce0ee, 0x6dcdfd59, 0x608edb80, 0x644fc637, 0x7a089632, 0x7ec98b85, 0x738aad5c, 0x774bb0eb,
        0x4f040d56, 0x4bc510e1, 0x46863638, 0x42472b8f, 0x5c007b8a, 0x58c1663d, 0x558240e4, 0x51435d53,
        0x251d3b9e, 0x21dc2629, 0x2c9f00f0, 0x285e1d47, 0x36194d42, 0x32d850f5, 0x3f9b762c, 0x3b5a6b9b,
        0x0315d626, 0x07d4cb91, 0x0a97ed48, 0x0e56f0ff, 0x1011a0fa, 0x14d0bd4d, 0x19939b94, 0x1d528623,
        0xf12f560e, 0xf5ee4bb9, 0xf8ad6d60, 0xfc6c70d7, 0xe22b20d2, 0xe6ea3d65, 0xeba91bbc, 0xef68060b,
        0xd727bbb6, 0xd3e6a601, 0xdea580d8, 0xda649d6f, 0xc423cd6a, 0xc0e2d0dd, 0xcda1f604, 0xc960ebb3,
        0xbd3e8d7e, 0xb9ff90c9, 0xb4bcb610, 0xb07daba7, 0xae3afba2, 0xaafbe615, 0xa7b8c0cc, 0xa379dd7b,
        0x9b3660c6, 0x9ff77d71, 0x92b45ba8, 0x9675461f, 0x8832161a, 0x8cf30bad, 0x81b02d74, 0x857130c3,
        0x5d8a9099, 0x594b8d2e, 0x5408abf7, 0x50c9b640, 0x4e8ee645, 0x4a4ffbf2, 0x470cdd2b, 0x43cdc09c,
        0x7b827d21, 0x7f436096, 0x7200464f, 0x76c15bf8, 0x68860bfd, 0x6c47164a, 0x61043093, 0x65c52d24,
        0x119b4be9, 0x155a565e, 0x18197087, 0x1cd86d30, 0x029f3d35, 0x065e2082, 0x0b1d065b, 0x0fdc1bec,
        0x3793a651, 0x3352bbe6, 0x3e119d3f, 0x3ad08088, 0x2497d08d, 0x2056cd3a, 0x2d15ebe3, 0x29d4f654,
        0xc5a92679, 0xc1683bce, 0xcc2b1d17, 0xc8ea00a0, 0xd6ad50a5, 0xd26c4d12, 0xdf2f6bcb, 0xdbee767c,
        0xe3a1cbc1, 0xe760d676, 0xea23f0af, 0xeee2ed18, 0xf0a5bd1d, 0xf464a0aa, 0xf9278673, 0xfde69bc4,
        0x89b8fd09, 0x8d79e0be, 0x803ac667, 0x84fbdbd0, 0x9abc8bd5, 0x9e7d9662, 0x933eb0bb, 0x97ffad0c,
        0xafb010b1, 0xab710d06, 0xa6322bdf, 0xa2f33668, 0xbcb4666d, 0xb8757bda, 0xb5365d03, 0xb1f740b4,
    ];

    public static function crc32Lookup(int $index): int
    {
        return self::$table[$index];
    }
}

final class Bz2BitReader
{
    private string $input;

    private int $pos;

    private int $bufferByte = 0x100;

    public function __construct(string $input, int $startPos = 0)
    {
        $this->input = $input;
        $this->pos = $startPos;
    }

    public function readBit(): int
    {
        if (($this->bufferByte & 0xFF) === 0) {
            if ($this->pos >= \strlen($this->input)) {
                return 0;
            }
            $this->bufferByte = (\ord($this->input[$this->pos++]) << 1) | 1;
        }
        $bit = ($this->bufferByte & 0x100) ? 1 : 0;
        $this->bufferByte <<= 1;

        return $bit;
    }

    public function readBits(int $n): int
    {
        if ($n > 31) {
            $high = $this->readBits($n - 16);
            $low = $this->readBits(16);

            return ($high * 0x10000) + $low;
        }
        $r = 0;
        for ($i = 0; $i < $n; ++$i) {
            $r = ($r << 1) | $this->readBit();
        }

        return $r;
    }
}

final class Bz2BitWriter
{
    /** @var list<int> */
    private array $bytes = [];

    private int $bufferByte = 1;

    public function writeBit(int $b): void
    {
        $this->bufferByte <<= 1;
        if ($b) {
            $this->bufferByte |= 1;
        }
        if ($this->bufferByte & 0x100) {
            $this->bytes[] = $this->bufferByte & 0xFF;
            $this->bufferByte = 1;
        }
    }

    public function writeByte(int $byte): void
    {
        if (1 === $this->bufferByte) {
            $this->bytes[] = $byte & 0xFF;
        } else {
            $this->writeBits(8, $byte);
        }
    }

    public function writeBits(int $n, int $value): void
    {
        if ($n > 32) {
            $low = $value & 0xFFFF;
            $high = intdiv($value - $low, 0x10000);
            $this->writeBits($n - 16, $high);
            $this->writeBits(16, $low);

            return;
        }
        for ($i = $n - 1; $i >= 0; --$i) {
            $this->writeBit(($value >> $i) & 1);
        }
    }

    public function flush(): void
    {
        while (1 !== $this->bufferByte) {
            $this->writeBit(0);
        }
    }

    public function toString(): string
    {
        if ([] === $this->bytes) {
            return '';
        }

        return \pack('C*', ...$this->bytes);
    }
}

final class Bz2StaticHuffman
{
    /** @var list<int> */
    private array $codeLengths;

    /** @var list<int> */
    private array $code = [];

    /**
     * @param list<int> $freq
     */
    public function __construct(array $freq, int $alphabetSize)
    {
        $mergedFreq = [];
        for ($i = 0; $i < $alphabetSize; ++$i) {
            $mergedFreq[$i] = ($freq[$i] << 9) | $i;
        }
        \sort($mergedFreq);
        $sortedFreq = [];
        foreach ($mergedFreq as $v) {
            $sortedFreq[] = $v >> 9;
        }
        Bz2HuffmanAllocator::allocate($sortedFreq, 20);
        $this->codeLengths = \array_fill(0, $alphabetSize, 0);
        foreach ($mergedFreq as $idx => $v) {
            $sym = $v & 0x1FF;
            $this->codeLengths[$sym] = $sortedFreq[$idx];
        }
    }

    /**
     * @param list<int> $array
     */
    public function cost(array $array, int $offset, int $length): int
    {
        $cost = 0;
        for ($i = 0; $i < $length; ++$i) {
            $cost += $this->codeLengths[$array[$offset + $i]];
        }

        return $cost;
    }

    public function computeCanonical(): void
    {
        $alphabetSize = \count($this->codeLengths);
        $merged = [];
        for ($i = 0; $i < $alphabetSize; ++$i) {
            $merged[$i] = ($this->codeLengths[$i] << 9) | $i;
        }
        \sort($merged);
        $this->code = \array_fill(0, $alphabetSize, 0);
        $code = 0;
        $prevLen = 0;
        foreach ($merged as $entry) {
            $curLen = $entry >> 9;
            $sym = $entry & 0x1FF;
            $code <<= ($curLen - $prevLen);
            $this->code[$sym] = $code++;
            $prevLen = $curLen;
        }
    }

    public function emit(Bz2BitWriter $out): void
    {
        $currentLength = $this->codeLengths[0];
        $out->writeBits(5, $currentLength);
        foreach ($this->codeLengths as $codeLength) {
            if ($currentLength < $codeLength) {
                $value = 2;
                $delta = $codeLength - $currentLength;
            } else {
                $value = 3;
                $delta = $currentLength - $codeLength;
            }
            while ($delta-- > 0) {
                $out->writeBits(2, $value);
            }
            $out->writeBit(0);
            $currentLength = $codeLength;
        }
    }

    public function encode(Bz2BitWriter $out, int $symbol): void
    {
        $out->writeBits($this->codeLengths[$symbol], $this->code[$symbol]);
    }
}

final class Bz2HuffmanAllocator
{
    /**
     * @param list<int> $array
     */
    public static function allocate(array &$array, int $maximumLength): void
    {
        $len = \count($array);
        if ($len <= 2) {
            if (2 === $len) {
                $array[1] = 1;
            }
            $array[0] = 1;

            return;
        }
        self::setExtendedParentPointers($array);
        $nodesToRelocate = self::findNodesToRelocate($array, $maximumLength);
        if (($array[0] % $len) >= $nodesToRelocate) {
            self::allocateNodeLengths($array);
        } else {
            $insertDepth = $maximumLength - self::fls($nodesToRelocate - 1);
            self::allocateNodeLengthsWithRelocation($array, $nodesToRelocate, $insertDepth);
        }
    }

    /**
     * @param list<int> $array
     */
    private static function first(array $array, int $i, int $nodesToMove): int
    {
        $length = \count($array);
        $limit = $i;
        $k = $length - 2;
        while ($i >= $nodesToMove && ($array[$i] % $length) > $limit) {
            $k = $i;
            $i -= ($limit - $i + 1);
        }
        $i = \max($nodesToMove - 1, $i);
        while ($k > ($i + 1)) {
            $temp = ($i + $k) >> 1;
            if (($array[$temp] % $length) > $limit) {
                $k = $temp;
            } else {
                $i = $temp;
            }
        }

        return $k;
    }

    /**
     * @param list<int> $array
     */
    private static function setExtendedParentPointers(array &$array): void
    {
        $length = \count($array);
        $array[0] += $array[1];
        for ($headNode = 0, $tailNode = 1, $topNode = 2; $tailNode < ($length - 1); ++$tailNode) {
            if ($topNode >= $length || $array[$headNode] < $array[$topNode]) {
                $temp = $array[$headNode];
                $array[$headNode++] = $tailNode;
            } else {
                $temp = $array[$topNode++];
            }
            if ($topNode >= $length || ($headNode < $tailNode && $array[$headNode] < $array[$topNode])) {
                $temp += $array[$headNode];
                $array[$headNode++] = $tailNode + $length;
            } else {
                $temp += $array[$topNode++];
            }
            $array[$tailNode] = $temp;
        }
    }

    /**
     * @param list<int> $array
     */
    private static function findNodesToRelocate(array $array, int $maximumLength): int
    {
        $currentNode = \count($array) - 2;
        for ($currentDepth = 1; $currentDepth < ($maximumLength - 1) && $currentNode > 1; ++$currentDepth) {
            $currentNode = self::first($array, $currentNode - 1, 0);
        }

        return $currentNode;
    }

    /**
     * @param list<int> $array
     */
    private static function allocateNodeLengths(array &$array): void
    {
        $firstNode = \count($array) - 2;
        $nextNode = \count($array) - 1;
        for ($currentDepth = 1, $availableNodes = 2; $availableNodes > 0; ++$currentDepth) {
            $lastNode = $firstNode;
            $firstNode = self::first($array, $lastNode - 1, 0);
            for ($i = $availableNodes - ($lastNode - $firstNode); $i > 0; --$i) {
                $array[$nextNode--] = $currentDepth;
            }
            $availableNodes = ($lastNode - $firstNode) << 1;
        }
    }

    /**
     * @param list<int> $array
     */
    private static function allocateNodeLengthsWithRelocation(array &$array, int $nodesToMove, int $insertDepth): void
    {
        $firstNode = \count($array) - 2;
        $nextNode = \count($array) - 1;
        $currentDepth = (1 === $insertDepth) ? 2 : 1;
        $nodesLeftToMove = (1 === $insertDepth) ? $nodesToMove - 2 : $nodesToMove;
        for ($availableNodes = $currentDepth << 1; $availableNodes > 0; ++$currentDepth) {
            $lastNode = $firstNode;
            $firstNode = ($firstNode <= $nodesToMove) ? $firstNode : self::first($array, $lastNode - 1, $nodesToMove);
            $offset = 0;
            if ($currentDepth >= $insertDepth) {
                $offset = \min($nodesLeftToMove, 1 << ($currentDepth - $insertDepth));
            } elseif ($currentDepth === ($insertDepth - 1)) {
                $offset = 1;
                if ($array[$firstNode] === $lastNode) {
                    ++$firstNode;
                }
            }
            for ($i = $availableNodes - ($lastNode - $firstNode + $offset); $i > 0; --$i) {
                $array[$nextNode--] = $currentDepth;
            }
            $nodesLeftToMove -= $offset;
            $availableNodes = ($lastNode - $firstNode + $offset) << 1;
        }
    }

    private static function fls(int $v): int
    {
        if ($v >= 0x100000000) {
            return 32 + self::fls(intdiv($v, 0x100000000));
        }
        if ($v >= 0x10000) {
            return 16 + self::fls(intdiv($v, 0x10000));
        }
        if ($v >= 0x100) {
            return 8 + self::fls(intdiv($v, 0x100));
        }
        if ($v >= 0x10) {
            return 4 + self::fls(intdiv($v, 0x10));
        }
        if ($v >= 0x4) {
            return 2 + self::fls(intdiv($v, 0x4));
        }
        if ($v >= 0x2) {
            return 1;
        }

        return 0;
    }
}
