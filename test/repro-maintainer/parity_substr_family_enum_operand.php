<?php
declare(strict_types=1);

enum Es: string { case B = 'hi'; }

foreach (['substr', 'substr_count', 'substr_replace'] as $fn) {
    try {
        if ($fn === 'substr') {
            $fn(Es::B, 0);
        } elseif ($fn === 'substr_count') {
            $fn(Es::B, 'h');
        } else {
            $fn(Es::B, 'y', 0);
        }
        echo "{$fn} ok\n";
    } catch (Throwable $e) {
        echo $fn, ' ', $e::class, ': ', $e->getMessage(), "\n";
    }
}
