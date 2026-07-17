<?php

declare(strict_types=1);

// Compile-only (#19964): grapheme_str_split runtime JIT for non-literal operands.
function probe_str_split_runtime(string $string, int $length = 1): array|false
{
    return grapheme_str_split($string, $length);
}
