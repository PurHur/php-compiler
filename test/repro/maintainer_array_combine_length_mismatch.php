<?php

declare(strict_types=1);

try {
    array_combine([1, 2], [3]);
    echo "no throw\n";
} catch (ValueError $e) {
    echo get_class($e), "\n";
    echo $e->getMessage(), "\n";
}
