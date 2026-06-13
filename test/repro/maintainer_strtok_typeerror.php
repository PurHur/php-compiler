<?php
// Issue #4587 — strtok() invalid operands must TypeError (ext/standard/string.c)
declare(strict_types=1);

foreach ([[[], ','], ['a,b,c', []]] as [$s, $tok]) {
    try {
        strtok($s, $tok);
        echo "uncaught\n";
    } catch (Throwable $e) {
        echo get_class($e), ': ', $e->getMessage(), "\n";
    }
}

$s = 'a,b,c';
echo strtok($s, ','), ' ', strtok(','), ' ', strtok(','), "\n";
