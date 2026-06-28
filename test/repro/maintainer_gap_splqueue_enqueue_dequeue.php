<?php

declare(strict_types=1);

$q = new SplQueue();
$q->enqueue(1);
echo $q->dequeue() === 1 ? "ok\n" : "fail\n";
