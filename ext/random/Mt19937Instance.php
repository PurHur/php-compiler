<?php

declare(strict_types=1);

namespace PHPCompiler\ext\random;

/**
 * Instance-scoped MT19937 engine (php-src ext/random/engine_mt19937.c; #13191).
 *
 * Algorithm matches {@see \PHPCompiler\ext\standard\VmMt19937} global state.
 */
final class Mt19937Instance
{
    public const MT_N = 624;

    public const MT_M = 397;

    public const MT_RAND_MT19937 = 0;

    public const MT_RAND_PHP = 1;

    /** @var list<int> */
    private array $state = [];

    private int $count = self::MT_N;

    private int $mode = self::MT_RAND_MT19937;

    public function seed(int $seed, int $mode = self::MT_RAND_MT19937): void
    {
        $this->mode = $mode;
        $this->state = [$seed & 0xFFFFFFFF];
        for ($i = 1; $i < self::MT_N; ++$i) {
            $prev = $this->state[$i - 1];
            $this->state[$i] = (1812433253 * (($prev ^ ($prev >> 30)) & 0xFFFFFFFF) + $i) & 0xFFFFFFFF;
        }
        $this->count = $i;
        $this->reload();
    }

    public function generate(): int
    {
        if ($this->count >= self::MT_N) {
            $this->reload();
        }
        $s1 = $this->state[$this->count++];
        $s1 ^= ($s1 >> 11) & 0xFFFFFFFF;
        $s1 ^= ($s1 << 7) & 0x9D2C5680;
        $s1 &= 0xFFFFFFFF;
        $s1 ^= ($s1 << 15) & 0xEFC60000;
        $s1 &= 0xFFFFFFFF;

        return ($s1 ^ ($s1 >> 18)) & 0xFFFFFFFF;
    }

    /** Unbiased int in [min, max] — php_random_range / rand_range32. */
    public function range(int $min, int $max): int
    {
        if ($max < $min) {
            throw new \ValueError(
                'Random\\Randomizer::getInt(): Argument #2 ($max) must be greater than or equal to argument #1 ($min)'
            );
        }
        if ($min === $max) {
            return $min;
        }

        $umax = $max - $min;
        if ($umax > 0xFFFFFFFF) {
            return $min + $this->range64($umax);
        }

        return $min + $this->range32($umax);
    }

    private function range32(int $umax): int
    {
        if (0xFFFFFFFF === $umax) {
            return $this->generate();
        }

        ++$umax;
        if (($umax & ($umax - 1)) === 0) {
            return $this->generate() & ($umax - 1);
        }

        $limit = 0xFFFFFFFF - (int) (0xFFFFFFFF % $umax) - 1;
        $result = $this->generate();
        while ($result > $limit) {
            $result = $this->generate();
        }

        return $result % $umax;
    }

    private function range64(int $umax): int
    {
        ++$umax;
        $limit = \PHP_INT_MAX - (int) (\PHP_INT_MAX % $umax) - 1;
        $result = $this->generate();
        while ($result > $limit) {
            $result = $this->generate();
        }

        return $result % $umax;
    }

    private function reload(): void
    {
        if (self::MT_RAND_PHP === $this->mode) {
            $this->reloadPhp();
        } else {
            $this->reloadMt19937();
        }
        $this->count = 0;
    }

    private function reloadMt19937(): void
    {
        $n = self::MT_N;
        $m = self::MT_M;
        for ($i = 0; $i < $n - $m; ++$i) {
            $this->state[$i] = $this->twist($this->state[$i + $m], $this->state[$i], $this->state[$i + 1], false);
        }
        for ($i = $n - $m; $i < $n - 1; ++$i) {
            $this->state[$i] = $this->twist($this->state[$i + $m - $n], $this->state[$i], $this->state[$i + 1], false);
        }
        $this->state[$n - 1] = $this->twist($this->state[$m - 1], $this->state[$n - 1], $this->state[0], false);
    }

    private function reloadPhp(): void
    {
        $n = self::MT_N;
        $m = self::MT_M;
        for ($i = 0; $i < $n - $m; ++$i) {
            $this->state[$i] = $this->twist($this->state[$i + $m], $this->state[$i], $this->state[$i + 1], true);
        }
        for ($i = $n - $m; $i < $n - 1; ++$i) {
            $this->state[$i] = $this->twist($this->state[$i + $m - $n], $this->state[$i], $this->state[$i + 1], true);
        }
        $this->state[$n - 1] = $this->twist($this->state[$m - 1], $this->state[$n - 1], $this->state[0], true);
    }

    private function twist(int $m, int $u, int $v, bool $phpMode): int
    {
        $mix = (self::hiBit($u) | self::loBits($v)) & 0xFFFFFFFF;
        $lo = $phpMode ? self::loBit($u) : self::loBit($v);
        $mask = (-$lo) & 0x9908B0DF;

        return ($m ^ (($mix >> 1) & 0x7FFFFFFF) ^ $mask) & 0xFFFFFFFF;
    }

    private static function hiBit(int $u): int
    {
        return $u & 0x80000000;
    }

    private static function loBit(int $u): int
    {
        return $u & 1;
    }

    private static function loBits(int $u): int
    {
        return $u & 0x7FFFFFFF;
    }

    /**
     * php-src ext/random/engine_mt19937.c — __serialize payload at index 1.
     *
     * @return array<int, string|int>
     */
    public function exportSerializedState(): array
    {
        $out = [];
        for ($i = 0; $i < self::MT_N; ++$i) {
            $out[$i] = self::stateWordToWireHex($this->state[$i] ?? 0);
        }
        $out[624] = $this->count;
        $out[625] = $this->mode;

        return $out;
    }

    /**
     * @param array<int|string, string|int> $data
     */
    public function restoreFromSerializedState(array $data): void
    {
        $this->state = [];
        for ($i = 0; $i < self::MT_N; ++$i) {
            $hex = $data[$i] ?? '00000000';
            $this->state[$i] = self::wireHexToStateWord((string) $hex);
        }
        $this->count = (int) ($data[624] ?? self::MT_N);
        $this->mode = (int) ($data[625] ?? self::MT_RAND_MT19937);
    }

    /** php-src engine_mt19937.c — little-endian word as memory-order hex pairs. */
    private static function stateWordToWireHex(int $word): string
    {
        $word &= 0xFFFFFFFF;

        return \sprintf(
            '%02x%02x%02x%02x',
            $word & 0xFF,
            ($word >> 8) & 0xFF,
            ($word >> 16) & 0xFF,
            ($word >> 24) & 0xFF
        );
    }

    private static function wireHexToStateWord(string $hex): int
    {
        $hex = \str_pad($hex, 8, '0', STR_PAD_LEFT);

        return (
            \hexdec(\substr($hex, 0, 2))
            | (\hexdec(\substr($hex, 2, 2)) << 8)
            | (\hexdec(\substr($hex, 4, 2)) << 16)
            | (\hexdec(\substr($hex, 6, 2)) << 24)
        ) & 0xFFFFFFFF;
    }
}
