<?php

declare(strict_types=1);

try {
    array_map(null, null, [1, 2]);
    echo "unexpected success\n";
} catch (TypeError $e) {
    echo $e->getMessage(), "\n";
}
