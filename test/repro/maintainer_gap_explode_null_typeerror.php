<?php
declare(strict_types=1);

try {
    explode(',', null);
    echo "uncaught\n";
} catch (TypeError $e) {
    echo $e->getMessage(), "\n";
}
