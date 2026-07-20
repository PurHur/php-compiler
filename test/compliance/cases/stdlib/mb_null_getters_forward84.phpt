--TEST--
mb_http_output/mb_internal_encoding/mb_language(null) getter on 8.4 (#21538)
--ENV--
PHP_COMPILER_PROFILE=8.4
--FILE--
<?php
error_reporting(E_ALL);
$deps = 0;
set_error_handler(static function (int $no, string $msg) use (&$deps): bool {
    if ($no === E_DEPRECATED) {
        ++$deps;
    }
    return true;
});
echo var_export(mb_internal_encoding(null), true), "\n";
echo var_export(mb_http_output(null), true), "\n";
echo var_export(mb_language(null), true), "\n";
echo mb_internal_encoding('ISO-8859-1') ? "set-ie\n" : "set-ie-fail\n";
echo var_export(mb_internal_encoding(null), true), "\n";
echo mb_internal_encoding('UTF-8') ? "reset-ie\n" : "reset-ie-fail\n";
echo mb_http_output('ASCII') ? "set-ho\n" : "set-ho-fail\n";
echo var_export(mb_http_output(null), true), "\n";
echo mb_http_output('UTF-8') ? "reset-ho\n" : "reset-ho-fail\n";
echo $deps > 0 ? "DEP\n" : "no-dep\n";
?>
--EXPECT--
'UTF-8'
'UTF-8'
'neutral'
set-ie
'ISO-8859-1'
reset-ie
set-ho
'ASCII'
reset-ho
no-dep
