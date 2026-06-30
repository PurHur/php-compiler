<?php

declare(strict_types=1);

function t(): void {
    parse_str('a=1&b=2');
    if (($a ?? null) !== '1' || ($b ?? null) !== '2') {
        echo "bad\n";
        var_dump($a ?? null, $b ?? null);
        exit(1);
    }
    echo "ok\n";
}

t();

