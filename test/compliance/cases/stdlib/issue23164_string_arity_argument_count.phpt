--TEST--
stdlib string wrong argc — ArgumentCountError not LogicException (#23164, Zend zend_API.c)
--FILE--
<?php
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
?>
--EXPECT--
str_repeat ArgumentCountError: str_repeat() expects exactly 2 arguments, 1 given
str_pad ArgumentCountError: str_pad() expects at least 2 arguments, 1 given
str_ireplace ArgumentCountError: str_ireplace() expects at least 3 arguments, 1 given
strtr ArgumentCountError: strtr() expects at least 2 arguments, 1 given
nl2br ArgumentCountError: nl2br() expects at least 1 argument, 0 given
addslashes ArgumentCountError: addslashes() expects exactly 1 argument, 0 given
bin2hex ArgumentCountError: bin2hex() expects exactly 1 argument, 0 given
soundex ArgumentCountError: soundex() expects exactly 1 argument, 0 given
chunk_split ArgumentCountError: chunk_split() expects at least 1 argument, 0 given
wordwrap ArgumentCountError: wordwrap() expects at least 1 argument, 0 given
str_split ArgumentCountError: str_split() expects at least 1 argument, 0 given
count_chars ArgumentCountError: count_chars() expects at least 1 argument, 0 given
strcoll ArgumentCountError: strcoll() expects exactly 2 arguments, 1 given
strcasecmp ArgumentCountError: strcasecmp() expects exactly 2 arguments, 1 given
similar_text ArgumentCountError: similar_text() expects at least 2 arguments, 1 given
metaphone ArgumentCountError: metaphone() expects at least 1 argument, 0 given
convert_uuencode ArgumentCountError: convert_uuencode() expects exactly 1 argument, 0 given
convert_uudecode ArgumentCountError: convert_uudecode() expects exactly 1 argument, 0 given
quoted_printable_encode ArgumentCountError: quoted_printable_encode() expects exactly 1 argument, 0 given
stripslashes ArgumentCountError: stripslashes() expects exactly 1 argument, 0 given
