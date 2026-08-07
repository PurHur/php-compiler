<?php
/**
 * #28525 — mb_convert_encoding() UTF-16BE/LE must encode codepoints, not substitute '?'.
 * php-src: ext/mbstring/mbstring.c + libmbfl utf16be/utf16le filters.
 */
echo 'BE=', bin2hex(mb_convert_encoding('A', 'UTF-16BE', 'UTF-8')), "\n";
echo 'LE=', bin2hex(mb_convert_encoding('A', 'UTF-16LE', 'UTF-8')), "\n";
echo 'JP_BE=', bin2hex(mb_convert_encoding('あ', 'UTF-16BE', 'UTF-8')), "\n";
echo 'JP_LE=', bin2hex(mb_convert_encoding('あ', 'UTF-16LE', 'UTF-8')), "\n";
echo 'EMOJI_BE=', bin2hex(mb_convert_encoding('😀', 'UTF-16BE', 'UTF-8')), "\n";
echo 'ISO=', bin2hex(mb_convert_encoding('café', 'ISO-8859-1', 'UTF-8')), "\n";
