--TEST--
stdlib urlencode()/rawurlencode() null soft-coerce on 8.4 forward profile JIT (#21188, re-#18733 #18912, ext/standard/url.c)
--ENV--
PHP_COMPILER_PROFILE=8.4
--JIT--
--FILE--
<?php
set_error_handler(static function (int $no): bool {
    return E_DEPRECATED === $no;
});
foreach (['urlencode', 'rawurlencode'] as $fn) {
    try {
        $r = $fn(null);
        echo $fn, ': ', var_export($r, true), "\n";
    } catch (TypeError $e) {
        echo $fn, ': ', $e->getMessage(), "\n";
    }
}
?>
--EXPECT--
urlencode: ''
rawurlencode: ''
