<?php

declare(strict_types=1);

foreach ([0, 1] as $argc) {
    try {
        if (0 === $argc) {
            parse_str();
        } else {
            parse_str('a=1');
        }
        echo "fail: uncaught argc={$argc}\n";
        exit(1);
    } catch (ArgumentCountError $e) {
        echo $e->getMessage(), "\n";
    }
}

echo "ok\n";
