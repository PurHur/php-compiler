<?php

declare(strict_types=1);

/**
 * #4387 — SplPriorityQueue / SplMaxHeap extract ordering.
 *
 * php-src: ext/spl/spl_heap.c
 */
$pq = new SplPriorityQueue();
$pq->setExtractFlags(SplPriorityQueue::EXTR_BOTH);
$pq->insert('low', 1);
$pq->insert('high', 100);
$first = $pq->extract();
$second = $pq->extract();
echo $first['data'], ':', $first['priority'], "\n";
echo $second['data'], ':', $second['priority'], "\n";

$max = new SplMaxHeap();
$max->insert(1);
$max->insert(3);
echo $max->extract(), "\n";
