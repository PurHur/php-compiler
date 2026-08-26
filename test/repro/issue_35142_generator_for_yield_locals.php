<?php
// Repro for #35142 — AOT for-loop / mutated locals across yield (Zend/zend_generators.c).
// Avoid print_r/var_dump (#23540).

function range_gen(int $n)
{
    for ($i = 0; $i < $n; $i++) {
        yield $i;
    }
}

function mutate_gen()
{
    $a = 0;
    yield $a;
    $a = 1;
    yield $a;
}

function param_gen($n)
{
    yield $n;
}

foreach (range_gen(3) as $v) {
    echo $v;
}
echo '|';
foreach (mutate_gen() as $v) {
    echo $v;
}
echo '|';
foreach (param_gen(7) as $v) {
    echo $v;
}
echo "\n";
