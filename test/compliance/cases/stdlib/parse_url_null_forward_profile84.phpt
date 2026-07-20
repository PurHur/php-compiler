--TEST--
stdlib parse_url(null) soft-coerce on 8.4 forward profile (#21188, re-#20110, ext/standard/url.c)
--ENV--
PHP_COMPILER_PROFILE=8.4
--FILE--
<?php
set_error_handler(static function (int $no): bool {
    return E_DEPRECATED === $no;
});
try {
    var_export(parse_url(null));
    echo "\n";
} catch (TypeError $e) {
    echo $e->getMessage(), "\n";
}
?>
--EXPECT--
array (
  'path' => '',
)
