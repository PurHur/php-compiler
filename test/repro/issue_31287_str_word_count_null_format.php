<?php

declare(strict_types=1);

// #31287 — null $format under strict_types → TypeError; omitted format / soft path OK.
try {
    str_word_count('a b', null);
    echo "fail null format\n";
} catch (TypeError $e) {
    echo $e->getMessage(), "\n";
}

echo str_word_count('a b'), "\n";
echo str_word_count('a b', 0), "\n";
