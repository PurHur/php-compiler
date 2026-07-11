<?php

declare(strict_types=1);

// Compile-only (#9793): grapheme_strimwidth compile-time fold for AOT lint.
function probe_strimwidth(string $string, int $start, int $width, ?string $encoding = null): string|false
{
    return grapheme_strimwidth($string, $start, $width, $encoding);
}
