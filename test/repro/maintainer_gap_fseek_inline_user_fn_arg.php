<?php

function take(string $a, mixed $b, mixed $c): void
{
    var_export($b);
    echo PHP_EOL;
}

$f = fopen('php://memory', 'w+');
fwrite($f, 'abcde');
rewind($f);
take('label', fseek($f, -2, SEEK_END), 0);
