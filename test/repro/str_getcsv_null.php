<?php

declare(strict_types=1);

try {
    str_getcsv(null);
    echo "uncaught\n";
} catch (TypeError $e) {
    echo $e->getMessage(), "\n";
}
