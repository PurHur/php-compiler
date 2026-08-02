<?php

declare(strict_types=1);

$f = new Fiber(function (): void {
    $v = Fiber::suspend('paused');
    echo 'resumed:', $v, "\n";
});
echo 'start:', $f->start(), "\n";
echo 'resume:', $f->resume('go'), "\n";
echo 'status:', ($f->isTerminated() ? 'done' : 'live'), "\n";
