<?php

declare(strict_types=1);

try {
    readlink(null);
    echo "fail\n";
} catch (TypeError $e) {
    echo $e->getMessage(), "\n";
}
