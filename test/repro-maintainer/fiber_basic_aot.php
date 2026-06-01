<?php
$fiber = new Fiber(function (): void {
    echo Fiber::suspend('first');
    echo "done\n";
});
echo $fiber->start(), "\n";
$fiber->resume();
