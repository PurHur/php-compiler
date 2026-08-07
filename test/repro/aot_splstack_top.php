<?php
// Repro #28704 — AOT SplStack::top() peeks LIFO head from `__spl_ht`.
$s = new SplStack();
$s->push(1);
$s->push(2);
$s->push(3);
echo 'top=', $s->top(), "\n";
foreach ($s as $v) {
    echo $v, ',';
}
echo "\n";
echo 'after_top=', $s->top(), "\n";
echo 'pop=', $s->pop(), "\n";
echo 'then_top=', $s->top(), "\n";
