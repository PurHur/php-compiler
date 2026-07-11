<?php
declare(strict_types=1);

foreach (['escapeshellarg', 'escapeshellcmd'] as $fn) {
    try {
        $fn("a\0b");
        echo $fn, ": accepted\n";
    } catch (ValueError $e) {
        echo $fn, ': rejected — ', $e->getMessage(), "\n";
    }
}
