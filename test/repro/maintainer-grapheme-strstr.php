<?php

declare(strict_types=1);

if (!function_exists('grapheme_strstr')) {
    fwrite(STDERR, "MISSING grapheme_strstr\n");
    exit(1);
}
if (!function_exists('grapheme_stristr')) {
    fwrite(STDERR, "MISSING grapheme_stristr\n");
    exit(1);
}

$haystack = "a\xCC\x81bc";
$needle = 'b';
echo grapheme_strstr($haystack, $needle), "\n";
echo grapheme_stristr('Äbc', 'ä'), "\n";
