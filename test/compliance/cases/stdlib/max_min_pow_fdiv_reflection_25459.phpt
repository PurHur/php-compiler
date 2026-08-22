--TEST--
max/min/pow/fdiv Reflection types + Zend named args (VM, issue #25459)
--FILE--
<?php
foreach (['max', 'min', 'pow', 'fdiv', 'fmod'] as $fn) {
    $r = new ReflectionFunction($fn);
    echo $fn, ' ret=', $r->hasReturnType() ? (string) $r->getReturnType() : '(none)';
    echo ' params=';
    foreach ($r->getParameters() as $p) {
        echo '$', $p->getName();
        if ($p->hasType()) {
            echo ':', (string) $p->getType();
        }
        if ($p->isVariadic()) {
            echo '...';
        }
        echo ',';
    }
    echo "\n";
}
echo 'pow_named=', var_export(pow(num: 2, exponent: 3), true), "\n";
echo 'fmod_named=', var_export(fmod(num1: 5.5, num2: 2.0), true), "\n";
try {
    pow(base: 2, exponent: 3);
    echo "legacy_pow_ok\n";
} catch (Throwable $e) {
    echo 'legacy_pow ERR=', $e->getMessage(), "\n";
}
?>
--EXPECT--
max ret=mixed params=$value:mixed,$values:mixed...,
min ret=mixed params=$value:mixed,$values:mixed...,
pow ret=object|int|float params=$num:mixed,$exponent:mixed,
fdiv ret=float params=$num1:float,$num2:float,
fmod ret=float params=$num1:float,$num2:float,
pow_named=8
fmod_named=1.5
legacy_pow ERR=Unknown named parameter $base
