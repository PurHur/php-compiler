--TEST--
stdlib parse_url(null) TypeError on 8.4 forward profile (#20110, ext/standard/url.c)
--ENV--
PHP_COMPILER_PROFILE=8.4
--FILE--
<?php
try {
    var_export(parse_url(null));
    echo " uncaught\n";
} catch (TypeError $e) {
    echo $e->getMessage(), "\n";
}
?>
--EXPECT--
parse_url(): Argument #1 ($url) must be of type string, null given
