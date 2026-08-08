--TEST--
mbstring mb_convert_encoding() BASE64 transfer encoding (#28980)
--FILE--
<?php
error_reporting(E_ALL);
$deps = 0;
set_error_handler(static function (int $n, string $m) use (&$deps): bool {
    if (str_contains($m, 'Handling Base64 via mbstring is deprecated')) {
        ++$deps;
    }
    return true;
});

echo mb_convert_encoding('Hello, 世界', 'BASE64'), "\n";
echo mb_convert_encoding('QQ==', 'UTF-8', 'BASE64'), "\n";
echo mb_convert_encoding('SGVsbG8sIOS4lueVjA==', 'UTF-8', 'BASE64'), "\n";
echo mb_convert_encoding('!!!', 'UTF-8', 'BASE64'), "\n";
echo mb_convert_encoding('Q!Q==', 'UTF-8', 'BASE64'), "\n";
echo in_array('BASE64', mb_list_encodings(), true) ? "listed\n" : "missing\n";
echo $deps > 0 ? "deprecated\n" : "no-deprecation\n";
--EXPECT--
SGVsbG8sIOS4lueVjA==
A
Hello, 世界
???
?A
listed
deprecated
