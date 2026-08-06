--TEST--
stdlib php_uname() invalid $mode ValueError on 8.4 forward profile (#28136, ext/standard/info.c)
--ENV--
PHP_COMPILER_PROFILE=8.4
--FILE--
<?php
foreach (['z', '', "\0", 'aa'] as $m) {
    try {
        php_uname($m);
        echo var_export($m, true), " ACCEPTED\n";
    } catch (ValueError $e) {
        echo var_export($m, true), ' ValueError: ', $e->getMessage(), "\n";
    }
}
echo php_uname('s') !== '' ? "valid_s_ok\n" : "valid_s_fail\n";
echo php_uname('a') !== '' ? "valid_a_ok\n" : "valid_a_fail\n";
?>
--EXPECT--
'z' ValueError: php_uname(): Argument #1 ($mode) must be one of "a", "m", "n", "r", "s", or "v"
'' ValueError: php_uname(): Argument #1 ($mode) must be a single character
'' . "\0" . '' ValueError: php_uname(): Argument #1 ($mode) must be one of "a", "m", "n", "r", "s", or "v"
'aa' ValueError: php_uname(): Argument #1 ($mode) must be a single character
valid_s_ok
valid_a_ok
