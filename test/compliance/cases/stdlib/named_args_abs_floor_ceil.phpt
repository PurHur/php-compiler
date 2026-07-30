--TEST--
abs / floor / ceil named num argument (VM, issue #23259)
--FILE--
<?php
var_export(abs(num: -3));
echo PHP_EOL;
var_export(floor(num: 1.5));
echo PHP_EOL;
var_export(ceil(num: 1.2));
echo PHP_EOL;
foreach (['abs', 'floor', 'ceil'] as $fn) {
    $rf = new ReflectionFunction($fn);
    foreach ($rf->getParameters() as $p) {
        echo $fn, ':', $p->getName(), PHP_EOL;
    }
}
try {
    abs(number: -3);
    echo "number accepted\n";
} catch (Throwable $e) {
    echo $e->getMessage(), PHP_EOL;
}
--EXPECT--
3
1.0
2.0
abs:num
floor:num
ceil:num
Unknown named parameter $number
