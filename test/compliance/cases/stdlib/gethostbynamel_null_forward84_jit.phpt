--TEST--
stdlib gethostbynamel(null) JIT — TypeError on 8.4 forward profile (#20555, re-#19098, ext/standard/dns.c)
--ENV--
PHP_COMPILER_PROFILE=8.4
--JIT--
--FILE--
<?php
try {
    gethostbynamel(null);
    echo "uncaught\n";
} catch (TypeError $e) {
    echo $e->getMessage(), "\n";
}
?>
--EXPECT--
gethostbynamel(): Argument #1 ($hostname) must be of type string, null given
