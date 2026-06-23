<?php

declare(strict_types=1);

try {
    strlen(null);
    echo "no error\n";
    exit(1);
} catch (TypeError $e) {
    echo $e->getMessage(), "\n";
}

echo strlen(''), "\n";
