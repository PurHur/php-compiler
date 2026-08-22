<?php

/**
 * Repro: Spl*::isEmpty() after drain must be true under AOT (#33973).
 *
 * Thin AOT omitted isEmpty proxies → silent null (#579) → always falsy.
 *
 * Zend: php test/repro/issue_33973_spl_isempty_thin_aot.php
 * AOT:  php bin/compile.php -o /tmp/ie33973 test/repro/issue_33973_spl_isempty_thin_aot.php && /tmp/ie33973
 */

$q = new SplQueue();
echo 'freshQ=', $q->isEmpty() ? 'Y' : 'N', "\n";
$q->enqueue(1);
$q->enqueue(2);
echo 'fullQ=', $q->isEmpty() ? 'Y' : 'N', '|', $q->count(), "\n";
$q->dequeue();
$q->dequeue();
echo 'drainQ=', $q->isEmpty() ? 'Y' : 'N', '|', $q->count(), "\n";

$d = new SplDoublyLinkedList();
$d->push('a');
$d->pop();
echo 'drainD=', $d->isEmpty() ? 'Y' : 'N', "\n";

$s = new SplStack();
$s->push('x');
$s->pop();
echo 'drainS=', $s->isEmpty() ? 'Y' : 'N', "\n";

$h = new SplMaxHeap();
$h->insert(3);
$h->extract();
echo 'drainH=', $h->isEmpty() ? 'Y' : 'N', '|', $h->count(), "\n";

$p = new SplPriorityQueue();
$p->insert('a', 1);
$p->extract();
echo 'drainP=', $p->isEmpty() ? 'Y' : 'N', '|', $p->count(), "\n";
