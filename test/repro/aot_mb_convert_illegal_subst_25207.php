<?php

declare(strict_types=1);

/**
 * AOT: mb_convert_encoding() honors mb_substitute_character() modes (#25207).
 *
 * php-src: ext/mbstring/mbstring.c — libmbfl illegal-byte substitution
 */

mb_substitute_character(63);
$before = mb_get_info('illegal_chars');
echo bin2hex(mb_convert_encoding("\x80\x81", 'UTF-8', 'UTF-8')), "\n";
echo mb_get_info('illegal_chars') - $before, "\n";

mb_substitute_character(0xFFFD);
echo bin2hex(mb_convert_encoding("\x80", 'UTF-8', 'UTF-8')), "\n";

mb_substitute_character('none');
var_export(mb_convert_encoding("\x80", 'UTF-8', 'UTF-8'));
echo "\n";

mb_substitute_character(63);
$s = "\x80";
echo bin2hex(mb_convert_encoding($s, 'UTF-8', 'UTF-8')), "\n";

mb_substitute_character('long');
echo mb_convert_encoding('あ', 'ASCII', 'UTF-8'), "\n";
