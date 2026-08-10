<?php

declare(strict_types=1);

try {
    array_rand([]);
    echo "FAIL: no exception\n";
} catch (ValueError $e) {
    echo $e->getMessage(), "\n";
}
