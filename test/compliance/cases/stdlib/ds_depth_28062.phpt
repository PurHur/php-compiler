--TEST--
stdlib Ds depth Pair/Deque/Stack/Queue/Heap + factories (#28062)
--ENV--
PHP_COMPILER_ENABLE_DS=1
--FILE--
<?php
declare(strict_types=1);
echo extension_loaded('ds') ? '1' : '0', "\n";
foreach (['Ds\\Pair','Ds\\Deque','Ds\\Stack','Ds\\Queue','Ds\\PriorityQueue','Ds\\Heap'] as $c) {
    echo class_exists($c) ? '1' : '0';
}
echo "\n";
foreach (['Ds\\Collection','Ds\\Hashable','Ds\\Sequence'] as $i) {
    echo interface_exists($i) ? '1' : '0';
}
echo "\n";
foreach (['Ds\\seq','Ds\\map','Ds\\set','Ds\\heap'] as $f) {
    echo function_exists($f) ? '1' : '0';
}
echo "\n";
$p = new Ds\Pair('a', 1);
$a = $p->toArray();
echo ($a['key'] === 'a' && $a['value'] === 1) ? '1' : '0', "\n";
$d = new Ds\Deque([1, 2, 3]);
echo $d->count(), "\n";
$s = new Ds\Stack();
$s->push(1, 2);
echo $s->pop(), "\n";
$q = new Ds\Queue([7, 8]);
echo $q->pop(), "\n";
$h = Ds\heap([1, 2]);
echo $h->count(), "\n";
$v = Ds\seq([1]);
echo $v instanceof Ds\Vector ? '1' : '0', "\n";
--EXPECT--
1
111111
111
1111
1
3
2
7
2
1
