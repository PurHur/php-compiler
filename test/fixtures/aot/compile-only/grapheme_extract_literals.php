<?php

declare(strict_types=1);

// Compile-only (#19965): grapheme_extract compile-time fold for AOT lint.
function probe_extract(string $haystack, int $size, int $extractType = 0, int $start = 0): string|false
{
    return grapheme_extract($haystack, $size, $extractType, $start);
}
