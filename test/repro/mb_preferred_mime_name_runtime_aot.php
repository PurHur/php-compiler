<?php

declare(strict_types=1);

/**
 * #35275 — mb_preferred_mime_name() with non-foldable runtime encoding under thin AOT.
 * php-src: ext/mbstring/mbstring.c PHP_FUNCTION(mb_preferred_mime_name)
 */
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

var_dump(mb_preferred_mime_name(enc_utf8()));
var_dump(mb_preferred_mime_name(enc_ascii()));
var_dump(mb_preferred_mime_name(enc_sjis()));
var_dump(mb_preferred_mime_name(enc_latin1()));
// Still folds:
$enc = 'UTF-8';
echo mb_preferred_mime_name($enc), "\n";
echo mb_preferred_mime_name('ASCII'), "\n";
echo mb_preferred_mime_name('SJIS'), "\n";
