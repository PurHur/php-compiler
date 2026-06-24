<?php

declare(strict_types=1);

try {
    require '';
    echo "require_ok\n";
} catch (ValueError $e) {
    echo $e->getMessage(), "\n";
}
