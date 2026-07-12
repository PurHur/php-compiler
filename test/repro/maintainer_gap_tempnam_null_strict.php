<?php

declare(strict_types=1);

try {
    tempnam(null, 'pfx');
    echo "uncaught\n";
} catch (TypeError $e) {
    echo $e->getMessage(), "\n";
}
