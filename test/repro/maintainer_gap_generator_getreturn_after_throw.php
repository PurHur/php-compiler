<?php

declare(strict_types=1);

function genThrow(): Generator
{
    yield 1;
    throw new Exception('x');
}

$g = genThrow();
$g->rewind();
try {
    $g->next();
} catch (Exception $e) {
    echo $e->getMessage(), "\n";
}

try {
    $g->getReturn();
    exit(1);
} catch (Exception $e) {
    echo get_class($e), ': ', $e->getMessage(), "\n";
}
