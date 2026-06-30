<?php

declare(strict_types=1);

function g(): Generator
{
    yield 1;
    throw new Exception('x');
}

$g = g();
$current = $g->current();
if (1 !== $current) {
    fwrite(STDERR, "expected current=1, got ".var_export($current, true)."\n");
    exit(1);
}
try {
    $g->next();
    fwrite(STDERR, "expected next() to throw\n");
    exit(1);
} catch (Exception $e) {
    if ('x' !== $e->getMessage()) {
        fwrite(STDERR, "expected message x, got ".$e->getMessage()."\n");
        exit(1);
    }
}
echo "ok\n";
