<?php

declare(strict_types=1);

try {
    array_rand([]);
    echo "empty: no_ex\n";
} catch (ValueError $e) {
    echo 'empty: ', $e->getMessage(), "\n";
}
try {
    array_rand(['a'], 0);
    echo "num_zero: no_ex\n";
} catch (ValueError $e) {
    echo 'num_zero: ', $e->getMessage(), "\n";
}
try {
    array_rand(['a', 'b'], 3);
    echo "num_exceed: no_ex\n";
} catch (ValueError $e) {
    echo 'num_exceed: ', $e->getMessage(), "\n";
}
