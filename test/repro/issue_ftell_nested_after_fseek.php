<?php

declare(strict_types=1);

function out(string $k, mixed $v): void
{
    echo "$k=$v\n";
}

$h = fopen('php://memory', 'r+');
fwrite($h, 'abc');
fseek($h, -1, SEEK_END);
fgetc($h);
fclose($h);

$h2 = fopen('php://memory', 'r+');
fwrite($h2, 'xyz');
out('ftell_nested', ftell($h2));
fclose($h2);
