--TEST--
stdlib gethostbyname(null) — DEP+coerce on 8.4 forward profile (#21446, reverts #20555, ext/standard/dns.c)
--ENV--
PHP_COMPILER_PROFILE=8.4
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
'' COERCED
