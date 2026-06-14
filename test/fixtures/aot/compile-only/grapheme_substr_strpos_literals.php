<?php

declare(strict_types=1);

// Compile-only (#3352): grapheme_substr/strpos compile-time fold for AOT lint.
function probe_substr(string $string, int $start, ?int $length = null): string|false
{
    return grapheme_substr($string, $start, $length);
}

function probe_strpos(string $haystack, string $needle, int $offset = 0): int|false
{
    return grapheme_strpos($haystack, $needle, $offset);
}
