--TEST--
stdlib bcceil/bcfloor/bcround Reflection + named args on PROFILE 8.4 (#26096)
--ENV--
PHP_COMPILER_PROFILE=8.4
--FILE--
<?php
foreach (['bcceil', 'bcfloor', 'bcround'] as $fn) {
    $rf = new ReflectionFunction($fn);
    $names = [];
    foreach ($rf->getParameters() as $p) {
        $names[] = $p->getName();
    }
    echo $fn, ' arity=', $rf->getNumberOfParameters(), ' [', implode(',', $names), "]\n";
}
echo bcceil(num: '1.2'), "\n";
echo bcfloor(num: '1.9'), "\n";
echo bcround(num: '1.55', precision: 1), "\n";
echo bcround(num: '1.55', precision: 1, mode: RoundingMode::HalfTowardsZero), "\n";
--EXPECT--
bcceil arity=1 [num]
bcfloor arity=1 [num]
bcround arity=3 [num,precision,mode]
2
1
1.6
1.5
