<?php

declare(strict_types=1);

function check_levenshtein_argcount(): void
{
    try {
        levenshtein('abc', 'abc', 1, 2, 3, 4);
    } catch (Throwable $ex) {
        echo get_class($ex), ': ', $ex->getMessage(), "\n";
    }
}

check_levenshtein_argcount();
