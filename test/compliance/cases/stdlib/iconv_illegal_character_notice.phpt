--TEST--
stdlib iconv() illegal character emits E_NOTICE (#13294, ext/iconv/iconv.c)
--FILE--
<?php
$noticed = false;
set_error_handler(static function (int $errno, string $message) use (&$noticed): bool {
    if (E_NOTICE === $errno && str_contains($message, 'illegal character')) {
        $noticed = true;
    }
    return true;
});
$result = iconv('UTF-8', 'ASCII', "\xC3\xBC");
echo (int) $noticed, "\n";
echo var_export($result, true), "\n";
--EXPECT--
1
false
