<?php

declare(strict_types=1);

$positional = dirname('/a/b/c/d', 2);
if ('/a/b' !== $positional) {
    fwrite(STDERR, "fail: positional dirname got {$positional}\n");
    exit(1);
}

$named = dirname('/a/b/c/d', levels: 2);
if ('/a/b' !== $named) {
    fwrite(STDERR, "fail: named levels dirname got {$named}\n");
    exit(1);
}

echo "ok\n";
