--TEST--
stdlib iconv() //TRANSLIT incomplete UTF-8 tail — incomplete multibyte notice (#17709, ext/iconv/iconv.c)
--FILE--
<?php
$noticed = false;
set_error_handler(static function (int $errno, string $message) use (&$noticed): bool {
    if (E_NOTICE === $errno && str_contains($message, 'incomplete multibyte character')) {
        $noticed = true;
    }
    return true;
});
$result = iconv('UTF-8', 'ASCII//TRANSLIT', "a\xE9");
echo (int) $noticed, "\n";
echo var_export($result, true), "\n";
--EXPECT--
1
false
