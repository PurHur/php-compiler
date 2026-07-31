--TEST--
curl_getinfo($ch, null) returns all-info array without DEP (#21882, ext/curl/interface.c)
--ENV--
PHP_COMPILER_ENABLE_CURL=1
--FILE--
<?php
error_reporting(E_ALL);
$deps = 0;
set_error_handler(static function (int $n, string $m) use (&$deps): bool {
    if (E_DEPRECATED === $n) {
        ++$deps;
    }
    return true;
});
$ch = curl_init('https://example.com');
$omit = curl_getinfo($ch);
$null = curl_getinfo($ch, null);
$zero = curl_getinfo($ch, 0);
echo 'omit=', is_array($omit) ? 'array' : gettype($omit), "\n";
echo 'null=', is_array($null) ? 'array' : gettype($null), "\n";
echo 'zero=', var_export($zero, true), "\n";
echo 'deps=', $deps, "\n";
echo 'same_keys=', (is_array($omit) && is_array($null) && array_keys($omit) === array_keys($null)) ? 'yes' : 'no', "\n";
?>
--EXPECT--
omit=array
null=array
zero=false
deps=0
same_keys=yes
