<?php

declare(strict_types=1);

enum E: int
{
    case A = 1;
}

setlocale(LC_TIME, 'C');
try {
    nl_langinfo(E::A);
    echo "no throw\n";
} catch (TypeError $e) {
    echo "TypeError\n";
}
