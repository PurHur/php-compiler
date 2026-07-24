--TEST--
Generator::getReturn() uncaught after throw→yield-in-catch fatals (issue #22869)
--FILE--
<?php
function gen() {
    try {
        yield 1;
    } catch (Exception $e) {
        yield 2;
    }
    return 3;
}

$g = gen();
$g->current();
$g->throw(new Exception('x'));
try {
    $g->getReturn();
    echo "fail-caught-path\n";
} catch (Exception $e) {
    echo 'caught:', $e->getMessage(), "\n";
}

$h = gen();
echo "A\n";
echo $h->current(), "\n";
echo "B\n";
echo $h->throw(new Exception('x')), "\n";
echo "C\n";
$r = $h->getReturn();
echo "D\n";
var_dump($r);
--EXPECT--
caught:Cannot get return value of a generator that hasn't returned
A
1
B
2
C
--EXPECT_EXIT--
255
