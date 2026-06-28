<?php

declare(strict_types=1);

function g(): Generator
{
    yield 1;
    throw new Exception('x');
}

$g = g();
echo "step1\n";
$g->next();
echo "step2\n";
