--TEST--
mbstring mb_encoding_aliases() libmbfl specials + HTML convert alias (#28983)
--FILE--
<?php
error_reporting(E_ALL);
$deps = [];
set_error_handler(static function (int $n, string $m) use (&$deps): bool {
    if (str_contains($m, 'Handling Base64 via mbstring is deprecated')) {
        $deps[] = 'base64';
    } elseif (str_contains($m, 'Handling Uuencode via mbstring is deprecated')) {
        $deps[] = 'uuencode';
    } elseif (str_contains($m, 'Handling QPrint via mbstring is deprecated')) {
        $deps[] = 'qprint';
    } elseif (str_contains($m, 'Handling HTML entities via mbstring is deprecated')) {
        $deps[] = 'html';
    }
    return true;
});

echo json_encode(mb_encoding_aliases('BASE64')), "\n";
echo json_encode(mb_encoding_aliases('UUENCODE')), "\n";
echo json_encode(mb_encoding_aliases('Quoted-Printable')), "\n";
echo json_encode(mb_encoding_aliases('qprint')), "\n";
echo json_encode(mb_encoding_aliases('HTML-ENTITIES')), "\n";
echo json_encode(mb_encoding_aliases('HTML')), "\n";
echo json_encode(mb_encoding_aliases('html')), "\n";
echo mb_convert_encoding('A<>&', 'HTML'), "\n";
echo mb_convert_encoding('A<>&', 'html'), "\n";
echo mb_preferred_mime_name('HTML'), "\n";
sort($deps);
echo implode(',', array_unique($deps)), "\n";
--EXPECT--
[]
[]
["qprint"]
["qprint"]
["HTML","html"]
["HTML","html"]
["HTML","html"]
A<>&
A<>&
HTML-ENTITIES
base64,html,qprint,uuencode
