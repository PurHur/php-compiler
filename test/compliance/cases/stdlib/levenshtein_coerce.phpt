--TEST--
stdlib levenshtein() — string/cost coercion and TypeError parity (#4190, ext/standard/levenshtein.c)
--FILE--
<?php
declare(strict_types=1);

echo levenshtein('kitten', 'sitting'), "\n";

try {
    levenshtein('a', 'b', '2', '1', '1');
} catch (Throwable $e) {
    echo get_class($e), "\n";
}

try {
    levenshtein(1, 2);
} catch (Throwable $e) {
    echo get_class($e), "\n";
}
--EXPECT--
3
TypeError
TypeError
