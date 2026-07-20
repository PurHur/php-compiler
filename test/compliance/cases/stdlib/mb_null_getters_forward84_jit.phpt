--TEST--
JIT mb_http_output/mb_internal_encoding/mb_language(null) getter on 8.4 (#21538)
--ENV--
PHP_COMPILER_PROFILE=8.4
--JIT--
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
echo $deps > 0 ? "DEP\n" : "no-dep\n";
?>
--EXPECT--
'UTF-8'
'UTF-8'
'neutral'
no-dep
