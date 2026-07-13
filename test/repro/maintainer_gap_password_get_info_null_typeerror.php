<?php

try {
    $info = password_get_info(null);
    echo $info['algoName'], "\n";
    echo null === $info['algo'] ? "algo_null\n" : "algo_set\n";
} catch (Throwable $e) {
    echo get_class($e), ': ', $e->getMessage(), "\n";
}

try {
    password_get_info(['x']);
} catch (Throwable $e) {
    echo 'array ', get_class($e), ': ', $e->getMessage(), "\n";
}
