<?php

declare(strict_types=1);

/**
 * Shared helpers for differential fuzz tooling (#36398).
 *
 * Deterministic xorshift32 RNG — same seed always yields the same program text.
 */

final class FuzzRng
{
    private int $state;

    public function __construct(int $seed)
    {
        // Avoid the all-zero state (xorshift fixed point).
        $this->state = $seed === 0 ? 0x9e3779b9 : ($seed & 0xffffffff);
    }

    public function next(): int
    {
        $x = $this->state;
        $x ^= ($x << 13) & 0xffffffff;
        $x ^= ($x >> 17) & 0xffffffff;
        $x ^= ($x << 5) & 0xffffffff;
        $this->state = $x & 0xffffffff;

        return $this->state;
    }

    public function int(int $min, int $max): int
    {
        if ($max < $min) {
            throw new InvalidArgumentException("max < min: {$max} < {$min}");
        }
        $span = $max - $min + 1;

        return $min + ($this->next() % $span);
    }

    /** @param list<mixed> $items */
    public function pick(array $items): mixed
    {
        if ($items === []) {
            throw new InvalidArgumentException('pick() on empty list');
        }

        return $items[$this->int(0, count($items) - 1)];
    }

    public function bool(int $trueWeight = 1, int $falseWeight = 1): bool
    {
        return $this->int(1, $trueWeight + $falseWeight) <= $trueWeight;
    }
}

/** Normalize crash / DIFF signatures for dedup (#36398). */
function fuzz_normalize_signature(string $kind, int $zendRc, int $gotRc, string $zendOut, string $gotOut): string
{
    $z = fuzz_collapse_output($zendOut);
    $g = fuzz_collapse_output($gotOut);

    return hash('sha256', $kind.'|'.$zendRc.'|'.$gotRc.'|'.$z.'|'.$g);
}

function fuzz_collapse_output(string $s): string
{
    $s = str_replace("\r\n", "\n", $s);
    // Drop absolute paths / build temp names so signatures are stable across hosts.
    $s = preg_replace('#/(?:tmp|compiler|app|var)/[^\s:]+#', '<path>', $s) ?? $s;
    $s = preg_replace('/\b0x[0-9a-fA-F]+\b/', '<hex>', $s) ?? $s;

    return trim($s);
}

function fuzz_repo_root(): string
{
    return dirname(__DIR__, 2);
}
