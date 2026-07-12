<?php
declare(ticks=1);
$log = [];
register_tick_function(function () use (&$log): void {
    $log[] = 'tick';
});
$a = 1;
$b = 2;
echo count($log), "\n";
