<?php

declare(strict_types=1);

$actual = bin2hex(mb_strtolower('İ', 'UTF-8'));
if ('69cc87' !== $actual) {
    fwrite(STDERR, "fail: expected 69cc87 got {$actual}\n");
    exit(1);
}

echo "mb_strtolower Turkish İ\n";
