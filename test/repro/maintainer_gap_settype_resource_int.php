<?php

declare(strict_types=1);

$r = fopen('php://memory', 'r');
$expected = (int) $r;
@settype($r, 'int');
if (!is_int($r)) {
    fwrite(STDERR, "expected int, got ".gettype($r)."\n");
    exit(1);
}
if ($r < 1) {
    fwrite(STDERR, "expected positive resource id, got={$r}\n");
    exit(1);
}
if ($r !== $expected) {
    fwrite(STDERR, "expected id={$expected}, got={$r}\n");
    exit(1);
}
echo "ok\n";
