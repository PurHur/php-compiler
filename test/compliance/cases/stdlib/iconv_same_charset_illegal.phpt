--TEST--
stdlib iconv() same-charset illegal byte returns false + notice (#25167, ext/iconv/iconv.c)
--FILE--
<?php
$noticed = false;
set_error_handler(static function (int $errno, string $message) use (&$noticed): bool {
    if (E_NOTICE === $errno && str_contains($message, 'illegal character')) {
        $noticed = true;
    }
    return true;
});
$result = iconv('UTF-8', 'UTF-8', "a\x80b");
echo (int) $noticed, "\n";
echo var_export($result, true), "\n";
$noticed = false;
$ignored = iconv('UTF-8', 'UTF-8//IGNORE', "a\x80b");
echo (int) $noticed, "\n";
echo bin2hex((string) $ignored), "\n";
// Overlong / surrogate rejected like glibc iconv (feeds UConverter subst path, #25203)
foreach (["a\xC0\x80b", "\xED\xA0\x80", "\xE0\x80\x80"] as $bad) {
    echo var_export(@iconv('UTF-8', 'UTF-8', $bad), true), "\n";
}
--EXPECT--
1
false
0
6162
false
false
false
