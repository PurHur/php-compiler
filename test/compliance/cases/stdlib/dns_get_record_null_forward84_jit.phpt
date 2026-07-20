--TEST--
stdlib dns_get_record(null) — DEP+coerce on 8.4 forward profile JIT (#21446, ext/standard/dns.c)
--ENV--
PHP_COMPILER_PROFILE=8.4
--JIT--
--FILE--
<?php
try {
    var_export(dns_get_record(null));
    echo " COERCED\n";
} catch (TypeError $e) {
    echo $e->getMessage(), "\n";
}
?>
--EXPECT--
array (
) COERCED
