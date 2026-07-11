<?php
declare(strict_types=1);

function bump_first(&...$args): void
{
    if (count($args) > 0) {
        $args[0] = 99;
    }
}

$x = 1;
bump_first($x);
if ($x !== 99) {
    fwrite(STDERR, "FAIL: bump_first expected 99 got {$x}\n");
    exit(1);
}

function bump_first_int(int &...$args): void
{
    if (count($args) > 0) {
        $args[0] = 99;
    }
}

$y = 1;
bump_first_int($y);
if ($y !== 99) {
    fwrite(STDERR, "FAIL: bump_first_int expected 99 got {$y}\n");
    exit(1);
}

function bump_after_prefix(int $prefix, &...$args): void
{
    if (count($args) > 0) {
        $args[0] = 99;
    }
}

$z = 1;
bump_after_prefix(0, $z);
if ($z !== 99) {
    fwrite(STDERR, "FAIL: bump_after_prefix expected 99 got {$z}\n");
    exit(1);
}

$closure = function (&...$args): void {
    if (count($args) > 0) {
        $args[0] = 99;
    }
};

$w = 1;
$closure($w);
if ($w !== 99) {
    fwrite(STDERR, "FAIL: closure expected 99 got {$w}\n");
    exit(1);
}

echo "ok\n";
