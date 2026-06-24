<?php

declare(strict_types=1);

try {
    include '';
    echo "include_ok\n";
} catch (ValueError $e) {
    echo $e->getMessage(), "\n";
}
