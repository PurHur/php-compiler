--TEST--
stdlib dns_get_record(null) — DEP+coerce on 8.4 forward profile (#21446, reverts #18786, ext/standard/dns.c)
--ENV--
PHP_COMPILER_PROFILE=8.4
--FILE--
<?php
try {
    var_export(dns_get_record(null));
    echo " COERCED\n";
} catch (TypeError $e) {
    echo $e->getMessage(), "\n";
}
try {
    $r = dns_get_record('');
    echo is_array($r) ? "empty ok\n" : "empty bad\n";
} catch (TypeError $e) {
    echo 'empty: ', $e->getMessage(), "\n";
}
?>
--EXPECT--
array (
) COERCED
empty ok
