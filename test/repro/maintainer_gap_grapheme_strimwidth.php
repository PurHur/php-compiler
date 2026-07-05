<?php

declare(strict_types=1);

if (!\function_exists('grapheme_strimwidth')) {
    echo "skip: grapheme_strimwidth not registered (ext/intl not loaded)\n";
    exit(0);
}

echo grapheme_strimwidth('こんにちは', 0, 3, '...'), "\n";
echo grapheme_strimwidth('hello', 0, 10), "\n";
