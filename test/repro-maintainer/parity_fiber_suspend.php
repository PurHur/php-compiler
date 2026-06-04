<?php
declare(strict_types=1);

$f = new Fiber(function (): void {
    echo "start\n";
    Fiber::suspend('resume');
});

$f->start();
