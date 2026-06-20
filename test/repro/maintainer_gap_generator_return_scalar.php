<?php
declare(strict_types=1);

function g(): Generator {
    return 1;
}

$gen = g();
var_export($gen);
echo "\n";
var_export($gen->getReturn());
echo "\n";
