<?php

declare(strict_types=1);

// Compile-only (#6246): grapheme_str_split compile-time fold for AOT lint.
function probe_str_split(string $string, int $length = 1): array|false
{
    return grapheme_str_split($string, $length);
}
