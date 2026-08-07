<?php
declare(strict_types=1);

/**
 * Repro for #28062 — Ds depth classes/interfaces + Ds\seq/map/set/heap factories.
 * Run: PHP_COMPILER_ENABLE_DS=1 php bin/vm.php test/repro/issue_28062_ds_depth.php
 */
echo 'ext=', extension_loaded('ds') ? '1' : '0', "\n";
foreach ([
    'Ds\\Vector', 'Ds\\Map', 'Ds\\Set', 'Ds\\Pair', 'Ds\\Deque', 'Ds\\Stack', 'Ds\\Queue',
    'Ds\\PriorityQueue', 'Ds\\Heap',
] as $c) {
    echo $c, '=', class_exists($c) ? '1' : '0', "\n";
}
foreach (['Ds\\Collection', 'Ds\\Hashable', 'Ds\\Sequence'] as $i) {
    echo $i, '=', interface_exists($i) ? '1' : '0', "\n";
}
foreach (['Ds\\seq', 'Ds\\map', 'Ds\\set', 'Ds\\heap'] as $f) {
    echo $f, '=', function_exists($f) ? '1' : '0', "\n";
}

$p = new Ds\Pair('k', 'v');
$pa = $p->toArray();
echo 'pair=', (isset($pa['key'], $pa['value']) && $pa['key'] === 'k' && $pa['value'] === 'v') ? '1' : '0', "\n";

$d = new Ds\Deque([1, 2]);
echo 'deque=', $d->count() === 2 ? '1' : '0', "\n";

$st = new Ds\Stack([1]);
$st->push(2);
echo 'stack=', ($st->pop() === 2 && $st->count() === 1) ? '1' : '0', "\n";

$q = new Ds\Queue([1, 2]);
echo 'queue=', ($q->pop() === 1 && $q->count() === 1) ? '1' : '0', "\n";

$h = new Ds\Heap([1, 2]);
$h->push(3);
echo 'heap=', ($h->count() === 3 && $h->pop() === 3) ? '1' : '0', "\n";

$pq = new Ds\PriorityQueue();
$pq->push('low', 1);
$pq->push('high', 10);
echo 'pq=', ($pq->pop() === 'high' && $pq->count() === 1) ? '1' : '0', "\n";

$sv = Ds\seq([9, 8]);
echo 'seq=', ($sv instanceof Ds\Vector && $sv->count() === 2) ? '1' : '0', "\n";
$sm = Ds\map(['a' => 1]);
echo 'fmap=', ($sm instanceof Ds\Map && $sm->get('a') === 1) ? '1' : '0', "\n";
$ss = Ds\set([1, 1, 2]);
echo 'fset=', ($ss instanceof Ds\Set && $ss->count() === 2) ? '1' : '0', "\n";
$sh = Ds\heap([4]);
echo 'fheap=', ($sh instanceof Ds\Heap && $sh->count() === 1) ? '1' : '0', "\n";
