--TEST--
PHP 8.4 pipe operator — nested unparenthesized arrow RHS (issue #16694, #11858)
--FILE--
<?php
declare(strict_types=1);

$x = 5 |> fn ($v) => $v * 2;
if (10 !== $x) {
    fwrite(STDERR, "expected 10, got {$x}\n");
    exit(1);
}

$y = 3 |> fn ($x) => $x + 1 |> fn ($x) => $x * 2;
if (8 !== $y) {
    fwrite(STDERR, "expected 8, got {$y}\n");
    exit(1);
}

echo "ok\n";
--EXPECT--
ok
