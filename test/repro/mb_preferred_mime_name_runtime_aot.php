<?php

declare(strict_types=1);

// Foldable locals — still covered by legacy path (#34298).
$enc = 'UTF-8';
echo mb_preferred_mime_name($enc), "\n";
echo mb_preferred_mime_name('ASCII'), "\n";
echo mb_preferred_mime_name('SJIS'), "\n";

// Non-foldable runtime encoding — NestedJIT leaf (#35275).
function enc_utf8(): string
{
    return 'UTF-8';
}
function enc_ascii(): string
{
    return 'ASCII';
}
function enc_sjis(): string
{
    return 'SJIS';
}
function enc_latin1(): string
{
    return 'ISO-8859-1';
}

echo mb_preferred_mime_name(enc_utf8()), "\n";
echo mb_preferred_mime_name(enc_ascii()), "\n";
echo mb_preferred_mime_name(enc_sjis()), "\n";
echo mb_preferred_mime_name(enc_latin1()), "\n";
