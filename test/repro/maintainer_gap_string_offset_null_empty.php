<?php

declare(strict_types=1);

$msg = 'Cannot assign an empty string to a string offset';

$s = 'abc';
try {
    $s[1] = null;
    fwrite(STDERR, "fail null: mutated ".bin2hex($s)."\n");
    exit(1);
} catch (\Error $e) {
    if ($msg !== $e->getMessage()) {
        fwrite(STDERR, "fail null msg: {$e->getMessage()}\n");
        exit(1);
    }
    if ('abc' !== $s) {
        fwrite(STDERR, "fail null: string mutated to {$s}\n");
        exit(1);
    }
}

$s = 'abc';
try {
    $s[1] = '';
    fwrite(STDERR, "fail empty: mutated ".bin2hex($s)."\n");
    exit(1);
} catch (\Error $e) {
    if ($msg !== $e->getMessage()) {
        fwrite(STDERR, "fail empty msg: {$e->getMessage()}\n");
        exit(1);
    }
    if ('abc' !== $s) {
        fwrite(STDERR, "fail empty: string mutated to {$s}\n");
        exit(1);
    }
}

$s = 'abc';
$s[1] = 'x';
if ('axc' !== $s) {
    fwrite(STDERR, "fail char: got {$s}\n");
    exit(1);
}

echo "ok\n";
