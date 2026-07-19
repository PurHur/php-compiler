--TEST--
intl normalizer_normalize()/Normalizer::normalize(null) TypeError on 8.4 forward (#21063)
--ENV--
PHP_COMPILER_PROFILE=8.4
--FILE--
<?php
if (!function_exists('normalizer_normalize') || !class_exists('Normalizer', false)) {
    die("skip ext/intl Normalizer not available");
}
foreach ([
    'normalizer_normalize' => static fn () => normalizer_normalize(null),
    'Normalizer::normalize' => static fn () => Normalizer::normalize(null),
] as $name => $call) {
    try {
        $r = $call();
        echo $name, ' COERCED ', var_export($r, true), "\n";
    } catch (TypeError $e) {
        echo $name, ' TypeError', "\n";
    }
}
echo var_export(normalizer_normalize(''), true), "\n";
?>
--EXPECT--
normalizer_normalize TypeError
Normalizer::normalize TypeError
''
