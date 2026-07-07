<?php

declare(strict_types=1);

// #17300 — array_find family string internal callbacks (is_int/is_string arity)
$fail = [];

try {
    if (!array_all([1, 2, 3], 'is_int')) {
        $fail[] = 'array_all(is_int)';
    }
} catch (Throwable $e) {
    $fail[] = 'array_all(is_int): '.$e->getMessage();
}

try {
    if (!array_all_key(['a' => 1, 'b' => 2], 'is_string')) {
        $fail[] = 'array_all_key(is_string)';
    }
} catch (Throwable $e) {
    $fail[] = 'array_all_key(is_string): '.$e->getMessage();
}

try {
    if (0 !== array_find_key([1, 2, 3], 'is_int')) {
        $fail[] = 'array_find_key(is_int)';
    }
} catch (Throwable $e) {
    $fail[] = 'array_find_key(is_int): '.$e->getMessage();
}

echo empty($fail) ? "ok\n" : 'fail: '.implode('; ', $fail)."\n";
