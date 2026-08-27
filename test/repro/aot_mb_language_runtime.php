<?php

declare(strict_types=1);

/**
 * #35259 — mb_language() with runtime language under thin AOT.
 * php-src: ext/mbstring/mbstring.c PHP_FUNCTION(mb_language)
 */
function lang(string $l): string
{
    return $l;
}

var_dump(mb_language(lang('uni')));
var_dump(mb_language());
var_dump(mb_language('English'));
var_dump(mb_language());
var_dump(mb_language(lang('de')));
var_dump(mb_language());
var_dump(mb_language('neutral'));
var_dump(mb_language());
try {
    mb_language(lang('nope'));
    echo "no error\n";
} catch (ValueError $e) {
    echo 'bad_lang=', $e->getMessage(), "\n";
}
var_dump(mb_language());
