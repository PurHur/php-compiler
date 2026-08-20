<?php
// Repro #32910 — ArrayIterator/SplStack count+append under AOT
$a = new ArrayIterator([1, 2, 3]);
echo 'ai_m=', $a->count(), "\n";
echo 'ai_f=', count($a), "\n";
$a->append(4);
echo 'ai_after=', $a->count(), '|', implode(',', iterator_to_array($a)), "\n";

$s = new SplStack();
$s->push(1);
$s->push(2);
echo 'stack=', $s->count(), '|', count($s), '|', $s->top(), "\n";
