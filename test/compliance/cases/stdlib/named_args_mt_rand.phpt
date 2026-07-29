--TEST--
mt_rand Reflection optional min/max + named args (VM, issue #24641)
--FILE--
<?php
$r = new ReflectionFunction('mt_rand');
$bits = [];
foreach ($r->getParameters() as $p) {
    $bits[] = $p->getName() . ($p->isOptional() ? '=' : '');
}
echo 'req=', $r->getNumberOfRequiredParameters(), ' [', implode(',', $bits), ']', PHP_EOL;

$n = mt_rand(min: 1, max: 2);
echo ($n === 1 || $n === 2) ? 'named_ok' : 'named_bad', PHP_EOL;

try {
    mt_rand(1);
    echo "1arg_accepted\n";
} catch (ArgumentCountError $e) {
    echo str_contains($e->getMessage(), 'exactly 2') ? "1arg_exactly2\n" : "1arg_other\n";
}

try {
    mt_rand(1, 2, 3);
    echo "3arg_accepted\n";
} catch (ArgumentCountError $e) {
    echo str_contains($e->getMessage(), 'exactly 2') ? "3arg_exactly2\n" : "3arg_other\n";
}

echo is_int(mt_rand()) ? 'zero_int' : 'zero_other', PHP_EOL;
echo is_int(mt_rand(10, 20)) ? 'pos_int' : 'pos_other', PHP_EOL;
--EXPECT--
req=0 [min=,max=]
named_ok
1arg_exactly2
3arg_exactly2
zero_int
pos_int
