<?php

declare(strict_types=1);

function gen(): Generator
{
    yield 1;
}

$gen = gen();
try {
    $gen->throw(new Exception('x'));
} catch (Exception $e) {
    echo $e->getMessage(), "\n";
}
echo $gen->valid() ? 'true' : 'false', "\n";
