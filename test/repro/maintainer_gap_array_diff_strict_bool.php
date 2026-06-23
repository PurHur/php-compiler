<?php

$a = [1, 2, 3];
$b = [2, 4];
try {
    array_diff($a, $b, true);
} catch (Throwable $e) {
    echo get_class($e), ': ', $e->getMessage(), "\n";
}
