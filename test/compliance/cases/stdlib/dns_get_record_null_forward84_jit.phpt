--TEST--
stdlib dns_get_record(null) — TypeError on 8.4 forward profile JIT (#18786, ext/standard/dns.c)
--ENV--
PHP_COMPILER_PROFILE=8.4
--JIT--
--FILE--
<?php
try {
    dns_get_record(null);
    echo "uncaught\n";
} catch (TypeError $e) {
    echo $e->getMessage(), "\n";
}
?>
--EXPECT--
dns_get_record(): Argument #1 ($hostname) must be of type string, null given
