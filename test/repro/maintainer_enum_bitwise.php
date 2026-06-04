<?php

declare(strict_types=1);

enum E: int
{
    case A = 1;
}

$ops = [
    'E::A & 1' => static fn () => E::A & 1,
    'E::A | 1' => static fn () => E::A | 1,
    'E::A ^ 1' => static fn () => E::A ^ 1,
    'E::A << 1' => static fn () => E::A << 1,
    'E::A >> 0' => static fn () => E::A >> 0,
    '~E::A' => static fn () => ~E::A,
];

foreach ($ops as $label => $fn) {
    try {
        $fn();
        echo "{$label}: no throw\n";
    } catch (TypeError $e) {
        echo "{$label}: TypeError:", $e->getMessage(), "\n";
    } catch (Throwable $e) {
        echo "{$label}: ", get_class($e), ':', $e->getMessage(), "\n";
    }
}
