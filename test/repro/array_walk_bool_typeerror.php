<?php
// #30144 — array_walk(false|true) TypeError says false|true not bool
declare(strict_types=1);

foreach (['array_walk', 'array_walk_recursive'] as $fn) {
    foreach ([false, true] as $v) {
        try {
            $fn($v, static fn () => null);
        } catch (Throwable $e) {
            echo $fn, ':', $e->getMessage(), PHP_EOL;
        }
    }
}
