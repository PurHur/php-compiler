<?php

declare(strict_types=1);

function out(string $k, mixed $v): void
{
    echo "$k=$v\n";
}

$h2 = fopen('php://memory', 'r+');
fwrite($h2, 'xyz');
out('ftell_nested', ftell($h2));
fclose($h2);
