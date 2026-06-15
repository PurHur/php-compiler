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
    echo "accepted\n";
} catch (TypeError $e) {
    echo 'te: ', $e->getMessage(), "\n";
}
