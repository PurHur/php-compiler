--TEST--
stdlib mb_convert_encoding() HTML-ENTITIES pseudo-encoding (#11212)
--FILE--
<?php
error_reporting(E_ALL);
set_error_handler(static function (int $n, string $m): bool {
    return str_contains($m, 'Handling HTML entities via mbstring is deprecated');
});
echo mb_convert_encoding('über', 'HTML-ENTITIES', 'UTF-8'), "\n";
echo mb_convert_encoding('&uuml;ber', 'UTF-8', 'HTML-ENTITIES'), "\n";
--EXPECT--
&uuml;ber
über
