--TEST--
stdlib gethostbyname(null) JIT — TypeError on 8.4 forward profile (#23858, ext/standard/dns.c)
--ENV--
PHP_COMPILER_PROFILE=8.4
--JIT--
--FILE--
<?php
try {
    var_export(gethostbyname(null));
    echo " COERCED\n";
} catch (TypeError $e) {
    echo $e->getMessage(), "\n";
}
?>
--EXPECT--
gethostbyname(): Argument #1 ($hostname) must be of type string, null given
