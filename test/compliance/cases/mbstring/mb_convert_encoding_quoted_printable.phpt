--TEST--
mbstring mb_convert_encoding() Quoted-Printable transfer encoding (#28982)
--FILE--
<?php
error_reporting(E_ALL);
$deps = 0;
set_error_handler(static function (int $n, string $m) use (&$deps): bool {
    if (str_contains($m, 'Handling QPrint via mbstring is deprecated')) {
        ++$deps;
    }
    return true;
});

echo mb_convert_encoding('A=B', 'Quoted-Printable'), "\n";
echo mb_convert_encoding('A=B', 'qprint'), "\n";
echo mb_convert_encoding('A=B', 'QPrint'), "\n";
echo mb_convert_encoding('A=B', 'quoted-printable'), "\n";
echo mb_convert_encoding('A=3DB', 'UTF-8', 'Quoted-Printable'), "\n";
echo mb_convert_encoding('A=3DB', 'UTF-8', 'qprint'), "\n";
echo bin2hex(mb_convert_encoding(str_repeat('x', 73), 'qprint')), "\n";
echo in_array('Quoted-Printable', mb_list_encodings(), true) ? "listed\n" : "missing\n";
echo $deps > 0 ? "deprecated\n" : "no-deprecation\n";
--EXPECT--
A=3DB
A=3DB
A=3DB
A=3DB
A=B
A=B
7878787878787878787878787878787878787878787878787878787878787878787878787878787878787878787878787878787878787878787878787878787878787878787878783d0d0a78
listed
deprecated
