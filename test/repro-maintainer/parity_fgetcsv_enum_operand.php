<?php

declare(strict_types=1);

enum Sep: string
{
    case Comma = ',';
}

$f = fopen('php://memory', 'r+');
fwrite($f, "a,b\n");
rewind($f);
try {
    fgetcsv($f, 0, Sep::Comma);
    echo "fgetcsv accepted\n";
} catch (TypeError $e) {
    echo 'fgetcsv ', $e::class, ': ', $e->getMessage(), "\n";
}
try {
    str_getcsv('a,b', Sep::Comma);
    echo "str_getcsv accepted\n";
} catch (TypeError $e) {
    echo 'str_getcsv ', $e::class, ': ', $e->getMessage(), "\n";
}
