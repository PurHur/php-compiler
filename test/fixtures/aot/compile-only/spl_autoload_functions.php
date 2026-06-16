<?php

declare(strict_types=1);

// AOT lint probe: spl_autoload_functions() JIT lowering (#3534).
function autoload_probe($c)
{
}
spl_autoload_register(function ($c) {
});
spl_autoload_register('autoload_probe');
$funcs = spl_autoload_functions();
echo count($funcs), "\n";
