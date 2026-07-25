<?php
/**
 * Repro for #23164 — string builtins wrong argc must throw ArgumentCountError (Zend zend_API.c).
 */
$cases = [
    'str_repeat' => static function () { str_repeat('a'); },
    'str_pad' => static function () { str_pad('a'); },
    'str_ireplace' => static function () { str_ireplace('a'); },
    'strtr' => static function () { strtr('a'); },
    'nl2br' => static function () { nl2br(); },
    'addslashes' => static function () { addslashes(); },
    'bin2hex' => static function () { bin2hex(); },
    'soundex' => static function () { soundex(); },
    'chunk_split' => static function () { chunk_split(); },
    'wordwrap' => static function () { wordwrap(); },
    'str_split' => static function () { str_split(); },
    'count_chars' => static function () { count_chars(); },
    'strcoll' => static function () { strcoll('a'); },
    'strcasecmp' => static function () { strcasecmp('a'); },
    'similar_text' => static function () { similar_text('a'); },
    'metaphone' => static function () { metaphone(); },
    'convert_uuencode' => static function () { convert_uuencode(); },
    'convert_uudecode' => static function () { convert_uudecode(); },
    'quoted_printable_encode' => static function () { quoted_printable_encode(); },
    'stripslashes' => static function () { stripslashes(); },
];
foreach ($cases as $name => $fn) {
    try {
        $fn();
        echo $name, " ran\n";
    } catch (Throwable $e) {
        echo $name, ' ', get_class($e), ': ', $e->getMessage(), "\n";
    }
}
