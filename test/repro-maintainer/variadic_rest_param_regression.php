<?php

declare(strict_types=1);

function test(int ...$args): void
{
    var_dump($args);
}

test(1, 2, 3);

function g(int $a, ...$rest): void
{
    var_dump($a, $rest);
}

g(1, 2, 3);
