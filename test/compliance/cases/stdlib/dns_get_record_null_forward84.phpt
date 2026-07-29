--TEST--
stdlib dns_get_record(null) — soft-null DEP+coerce on 8.4 forward profile (#24965, re-#24178, reverts #23858, ext/standard/dns.c)
--ENV--
PHP_COMPILER_PROFILE=8.4
--FILE--
<?php
set_error_handler(static function (int $no, string $msg): bool {
    if (E_DEPRECATED === $no) {
        echo "DEP\n";
        return true;
    }
    return false;
});
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
DEP
array (
) COERCED
empty ok
