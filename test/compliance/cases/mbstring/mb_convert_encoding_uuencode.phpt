--TEST--
mbstring mb_convert_encoding() UUENCODE transfer encoding (#28981)
--FILE--
<?php
error_reporting(E_ALL);
$deps = 0;
set_error_handler(static function (int $n, string $m) use (&$deps): bool {
    if (str_contains($m, 'Handling Uuencode via mbstring is deprecated')) {
        ++$deps;
    }
    return true;
});

echo bin2hex(mb_convert_encoding('Hi', 'UUENCODE')), "\n";
echo bin2hex(mb_convert_encoding('Hi', 'uuencode')), "\n";
echo bin2hex(mb_convert_encoding('Hi', 'x-uuencode')), "\n";
echo bin2hex(mb_convert_encoding('', 'UUENCODE')), "\n";
$enc = mb_convert_encoding('Hi', 'UUENCODE');
echo mb_convert_encoding($enc, 'UTF-8', 'UUENCODE'), "\n";
echo mb_convert_encoding($enc, 'UTF-8', 'x-uuencode'), "\n";
echo bin2hex(mb_convert_encoding('nope', 'UTF-8', 'UUENCODE')), "\n";
echo bin2hex(mb_convert_encoding('Hi', 'UUENCODE', 'UUENCODE')), "\n";
echo mb_convert_encoding($enc, 'UUENCODE', 'UUENCODE'), "\n";
echo in_array('UUENCODE', mb_list_encodings(), true) ? "listed\n" : "missing\n";
echo $deps > 0 ? "deprecated\n" : "no-deprecation\n";
--EXPECT--
626567696e20303634342066696c656e616d650a22322644600a
626567696e20303634342066696c656e616d650a22322644600a
626567696e20303634342066696c656e616d650a22322644600a

Hi
Hi

Hi
listed
deprecated
