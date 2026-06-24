<?php

declare(strict_types=1);

try {
    scandir('');
    echo "false\n";
} catch (ValueError $e) {
    echo $e->getMessage(), "\n";
}
