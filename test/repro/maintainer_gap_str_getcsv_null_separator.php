<?php

declare(strict_types=1);

try {
    str_getcsv('a,b', null);
    echo "uncaught\n";
} catch (TypeError $e) {
    echo $e->getMessage(), "\n";
}
