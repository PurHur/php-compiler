<?php

declare(strict_types=1);

/**
 * #35263 — mb_substitute_character() with runtime setter under thin AOT.
 * php-src: ext/mbstring/mbstring.c PHP_FUNCTION(mb_substitute_character)
 */
function box($x)
{
    return $x;
}

var_dump(mb_substitute_character());
var_dump(mb_substitute_character(box('none')));
var_dump(mb_substitute_character());
var_dump(mb_substitute_character(box('long')));
var_dump(mb_substitute_character());
var_dump(mb_substitute_character('entity'));
var_dump(mb_substitute_character());
var_dump(mb_substitute_character(box(42)));
var_dump(mb_substitute_character());
var_dump(mb_substitute_character(63));
var_dump(mb_substitute_character());
try {
    mb_substitute_character(box('nope'));
    echo "no error\n";
} catch (ValueError $e) {
    echo 'bad_str=', $e->getMessage(), "\n";
}
var_dump(mb_substitute_character());
try {
    mb_substitute_character(box(-1));
    echo "no error\n";
} catch (ValueError $e) {
    echo 'bad_cp=', $e->getMessage(), "\n";
}
var_dump(mb_substitute_character());
