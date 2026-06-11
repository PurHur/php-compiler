<?php
declare(strict_types=1);

if (!function_exists('grapheme_str_split')) {
    fwrite(STDERR, "MISSING grapheme_str_split\n");
    exit(1);
}

// Single grapheme: e + combining acute
$one = grapheme_str_split("e\xCC\x81");
var_export($one);
echo "\n";

// ASCII — one grapheme per char
$ascii = grapheme_str_split('abc');
var_export($ascii);
echo "\n";

// Optional length argument (max graphemes per chunk)
$chunked = grapheme_str_split('abcdef', 2);
var_export($chunked);
echo "\n";
