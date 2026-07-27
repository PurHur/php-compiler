--TEST--
stdlib dns_get_record(null) — TypeError on 8.4 forward profile (#23858/#23856, reverts #21446, ext/standard/dns.c)
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
dns_get_record(): Argument #1 ($hostname) must be of type string, null given
empty ok
