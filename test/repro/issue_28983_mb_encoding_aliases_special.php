<?php
/**
 * Repro #28983 — mb_encoding_aliases() special encodings + HTML convert alias.
 * php-src: ext/mbstring/mbstring.c / libmbfl mbfilter_*.c
 */
error_reporting(E_ALL);
$deps = [];
set_error_handler(static function (int $n, string $m) use (&$deps): bool {
    if (preg_match('/Handling (Base64|Uuencode|QPrint|HTML entities) via mbstring is deprecated/', $m, $m2)) {
        $deps[] = $m2[1];
    }

    return true;
});

foreach (['BASE64', 'UUENCODE', 'Quoted-Printable', 'HTML-ENTITIES', 'HTML'] as $e) {
    echo $e, ': ', json_encode(mb_encoding_aliases($e)), "\n";
}
echo 'HTML convert: ', var_export(mb_convert_encoding('A<>&', 'HTML'), true), "\n";
sort($deps);
echo 'deps: ', implode(',', array_unique($deps)), "\n";
