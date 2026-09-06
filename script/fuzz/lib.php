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

/**
 * Hierarchical delta-debug reducer (#36398).
 *
 * Keeps the PHP header / declare / @fuzz metadata block, then removes body lines
 * in shrinking chunks (ddmin) before a final single-line greedy pass. Callable
 * $isInteresting must return true while the oracle failure still reproduces.
 *
 * @param callable(string):bool $isInteresting
 */
function fuzz_reduce_source(string $src, callable $isInteresting): string
{
    if (!$isInteresting($src)) {
        throw new InvalidArgumentException('fuzz_reduce_source: input is not interesting');
    }

    $lines = preg_split("/\r\n|\n|\r/", $src) ?: [];
    $headerEnd = fuzz_reduce_header_end($lines);
    $body = array_slice($lines, $headerEnd + 1);
    $header = array_slice($lines, 0, $headerEnd + 1);

    $body = fuzz_reduce_ddmin_chunks($header, $body, $isInteresting);
    $body = fuzz_reduce_greedy_lines($header, $body, $isInteresting);

    $out = implode("\n", array_merge($header, $body));
    if (!str_ends_with($out, "\n")) {
        $out .= "\n";
    }

    return $out;
}

/**
 * @param list<string> $lines
 */
function fuzz_reduce_header_end(array $lines): int
{
    $headerEnd = 0;
    foreach ($lines as $i => $line) {
        if ($i === 0) {
            continue;
        }
        if (preg_match('/^\s*(?:\/\/|#)/', $line) === 1 || trim($line) === '' || str_starts_with(ltrim($line), 'declare')) {
            $headerEnd = $i;
            continue;
        }
        break;
    }

    return $headerEnd;
}

/**
 * @param list<string> $header
 * @param list<string> $body
 * @param callable(string):bool $isInteresting
 * @return list<string>
 */
function fuzz_reduce_ddmin_chunks(array $header, array $body, callable $isInteresting): array
{
    $n = count($body);
    if ($n === 0) {
        return $body;
    }

    $subsetSize = max(1, intdiv($n, 2));
    while ($subsetSize >= 1) {
        $changed = true;
        while ($changed) {
            $changed = false;
            $n = count($body);
            if ($n === 0) {
                return $body;
            }
            $offset = 0;
            while ($offset < $n) {
                $len = min($subsetSize, $n - $offset);
                $trial = $body;
                array_splice($trial, $offset, $len);
                if ($trial === $body) {
                    break;
                }
                $candidate = fuzz_reduce_join($header, $trial);
                if ($isInteresting($candidate)) {
                    $body = $trial;
                    $n = count($body);
                    $changed = true;
                    // stay at same offset — next chunk slid into place
                    continue;
                }
                $offset += $len;
            }
        }
        if ($subsetSize === 1) {
            break;
        }
        $subsetSize = max(1, intdiv($subsetSize, 2));
    }

    return $body;
}

/**
 * @param list<string> $header
 * @param list<string> $body
 * @param callable(string):bool $isInteresting
 * @return list<string>
 */
function fuzz_reduce_greedy_lines(array $header, array $body, callable $isInteresting): array
{
    $changed = true;
    while ($changed) {
        $changed = false;
        $i = 0;
        while ($i < count($body)) {
            if (trim($body[$i]) === '') {
                ++$i;
                continue;
            }
            $trial = $body;
            array_splice($trial, $i, 1);
            $candidate = fuzz_reduce_join($header, $trial);
            if ($isInteresting($candidate)) {
                $body = $trial;
                $changed = true;
                continue;
            }
            ++$i;
        }
    }

    return $body;
}

/**
 * @param list<string> $header
 * @param list<string> $body
 */
function fuzz_reduce_join(array $header, array $body): string
{
    $out = implode("\n", array_merge($header, $body));
    if (!str_ends_with($out, "\n")) {
        $out .= "\n";
    }

    return $out;
}

/** Count non-empty lines (Done-when ≤15-line reproducers). */
function fuzz_count_nonempty_lines(string $src): int
{
    $n = 0;
    foreach (preg_split("/\r\n|\n|\r/", $src) ?: [] as $line) {
        if (trim($line) !== '') {
            ++$n;
        }
    }

    return $n;
}
