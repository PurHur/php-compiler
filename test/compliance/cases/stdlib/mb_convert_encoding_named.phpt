--TEST--
stdlib mb_convert_encoding() to_encoding:/from_encoding: named parameters (#16886, ext/mbstring/mbstring.stub.php)
--FILE--
<?php
declare(strict_types=1);
error_reporting(E_ALL);
set_error_handler(static function (int $n, string $m): bool {
    return str_contains($m, 'Handling HTML entities via mbstring is deprecated');
});
$latin1 = "\xE9";
$out = mb_convert_encoding($latin1, to_encoding: 'UTF-8', from_encoding: 'ISO-8859-1');
echo $out === "\xC3\xA9" ? "ok\n" : "fail\n";
echo mb_convert_encoding('über', to_encoding: 'HTML-ENTITIES', from_encoding: 'UTF-8'), "\n";
--EXPECT--
ok
&uuml;ber
