<?php

declare(strict_types=1);

// Compile-only (#7221): grapheme_strstr/stristr compile-time fold for AOT.
function probe(string $haystack, string $needle): string|false
{
    return grapheme_strstr($haystack, $needle);
}

function probe_ci(string $haystack, string $needle): string|false
{
    return grapheme_stristr($haystack, $needle);
}
