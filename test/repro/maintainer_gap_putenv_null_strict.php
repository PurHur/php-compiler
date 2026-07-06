<?php

declare(strict_types=1);

try {
    putenv(null);
    echo "no exception\n";
} catch (TypeError $e) {
    echo $e->getMessage(), "\n";
}
