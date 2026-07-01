<?php

declare(strict_types=1);

class ExtendSplQueue extends SplQueue
{
}

$q = new ExtendSplQueue();
$q->push(1);
$q->push(2);
echo 'count='.$q->count()."\n";
echo "ok\n";
