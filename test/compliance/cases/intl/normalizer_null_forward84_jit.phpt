--TEST--
JIT intl normalizer_normalize(null) TypeError on 8.4 forward (#21063)
--ENV--
PHP_COMPILER_PROFILE=8.4
--JIT--
--FILE--
<?php
if (!function_exists('normalizer_normalize')) {
    die("skip ext/intl Normalizer not available");
}
try {
    $r = normalizer_normalize(null);
    echo 'COERCED ', var_export($r, true), "\n";
} catch (TypeError $e) {
    echo "TypeError\n";
}
echo var_export(normalizer_normalize(''), true), "\n";
?>
--EXPECT--
TypeError
''
