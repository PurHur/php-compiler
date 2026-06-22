<?php

declare(strict_types=1);

function gen(): Generator
{
    yield 1;
    yield 2;
}

$g = gen();
foreach ($g as $_) {
}

try {
    echo iterator_count($g), "\n";
} catch (Exception $e) {
    echo $e->getMessage(), "\n";
}
