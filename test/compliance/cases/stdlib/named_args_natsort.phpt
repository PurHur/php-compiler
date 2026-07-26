--TEST--
natsort/natcasesort named array; reject phantom flags (VM, issue #23243)
--FILE--
<?php
foreach (['natsort', 'natcasesort'] as $fn) {
    $rf = new ReflectionFunction($fn);
    $names = [];
    foreach ($rf->getParameters() as $p) {
        $names[] = $p->getName();
    }
    echo $fn, ':', implode(',', $names), PHP_EOL;
}
$a = ['10', '2'];
natsort(array: $a);
echo implode(',', array_values($a)), PHP_EOL;
$b = ['B', 'a'];
natcasesort(array: $b);
echo implode(',', array_values($b)), PHP_EOL;
try {
    $c = ['10', '2'];
    natsort($c, flags: 0);
    echo "flags_accepted\n";
} catch (Throwable $e) {
    echo $e->getMessage(), PHP_EOL;
}
--EXPECT--
natsort:array
natcasesort:array
2,10
a,B
Unknown named parameter $flags
