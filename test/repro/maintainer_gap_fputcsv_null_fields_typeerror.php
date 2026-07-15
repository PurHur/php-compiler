<?php

declare(strict_types=1);

$fp = fopen('php://memory', 'w+');
try {
    fputcsv($fp, null);
    echo "uncaught\n";
} catch (TypeError $e) {
    echo $e->getMessage(), "\n";
}
