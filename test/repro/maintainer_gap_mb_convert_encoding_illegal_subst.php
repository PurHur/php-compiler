<?php

declare(strict_types=1);

/**
 * #25207 - mb_convert_encoding / mb_convert_variables honor mb_substitute_character
 * on illegal UTF-8 bytes (including same-charset), and bump illegal_chars.
 *
 * php-src: ext/mbstring/mbstring.c + libmbfl mbfl_filt_conv_illegal_output()
 */

mb_substitute_character(63);
$before = mb_get_info('illegal_chars');
$hex = bin2hex(mb_convert_encoding("\x80\x81", 'UTF-8', 'UTF-8'));
$after = mb_get_info('illegal_chars');
echo "subst63=$hex illegal $before->$after\n";

mb_substitute_character(0xFFFD);
echo 'substFFFD='.bin2hex(mb_convert_encoding("\x80", 'UTF-8', 'UTF-8'))."\n";

mb_substitute_character('none');
$none = mb_convert_encoding("\x80", 'UTF-8', 'UTF-8');
echo 'substNone='.var_export($none, true)."\n";

mb_substitute_character(63);
$v = "\x80";
mb_convert_variables('UTF-8', 'UTF-8', $v);
echo 'vars='.bin2hex($v)."\n";
