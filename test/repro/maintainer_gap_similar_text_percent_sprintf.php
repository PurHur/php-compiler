<?php

declare(strict_types=1);

similar_text('abcdef', 'abcdeg', $pct);
$formatted = sprintf('%.17F', $pct);
$expected = sprintf('%.17F', 5 * 200.0 / 12);
if ($formatted !== $expected) {
    echo "fail: pct={$formatted} expected={$expected}\n";
    exit(1);
}
echo "ok\n";
