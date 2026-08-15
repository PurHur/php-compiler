<?php

declare(strict_types=1);

// #31287 — AOT-safe repro (TypeError only).
try {
    str_word_count('a b', null);
    echo "fail null format\n";
} catch (TypeError $e) {
    echo $e->getMessage(), "\n";
}
