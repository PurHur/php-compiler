<?php

declare(strict_types=1);

function gen(): Generator
{
    yield 1;
    throw new Exception('x');
}

$g = gen();
$g->rewind();
try {
    $g->next();
} catch (Exception $e) {
    echo $e->getMessage(), "\n";
}
echo $g->valid() ? 'true' : 'false', "\n";
