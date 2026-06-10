<?php

declare(strict_types=1);

// Compile-only (#7128): grapheme_str_contains() runtime LLVM path must link for AOT.
function probe(string $haystack, string $needle): bool
{
    return grapheme_str_contains($haystack, $needle);
}
