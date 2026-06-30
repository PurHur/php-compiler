<?php

declare(strict_types=1);

$a = [1, 2, 3];
end($a);

$concat = 'end=' . var_export(end($a), true);

if ('end=3' !== $concat) {
    fwrite(STDERR, "concat end: expected end=3, got {$concat}\n");
    exit(1);
}

echo "ok\n";
