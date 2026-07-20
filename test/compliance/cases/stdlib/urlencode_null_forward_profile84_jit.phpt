--TEST--
stdlib urlencode()/rawurlencode()/urldecode()/rawurldecode() null soft-coerce on 8.4 forward profile JIT (#21188, re-#19272, ext/standard/url.c)
--ENV--
PHP_COMPILER_PROFILE=8.4
--JIT--
--FILE--
<?php
set_error_handler(static function (int $no): bool {
    return E_DEPRECATED === $no;
});
foreach (['urlencode', 'rawurlencode', 'urldecode', 'rawurldecode'] as $fn) {
    try {
        echo $fn, ': ', var_export($fn(null), true), "\n";
    } catch (TypeError $e) {
        echo $fn, ': ', $e->getMessage(), "\n";
    }
}
?>
--EXPECT--
urlencode: ''
rawurlencode: ''
urldecode: ''
rawurldecode: ''
