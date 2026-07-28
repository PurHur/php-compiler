--TEST--
stdlib dns_get_record(null) — soft-null DEP+coerce on 8.4 forward profile JIT (#24178, ext/standard/dns.c)
--ENV--
PHP_COMPILER_PROFILE=8.4
--JIT--
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
?>
--EXPECT--
DEP
array (
) COERCED
