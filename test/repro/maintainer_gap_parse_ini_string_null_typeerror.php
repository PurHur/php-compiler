<?php

declare(strict_types=1);

try {
    parse_ini_string(null);
    echo "no_throw\n";
} catch (TypeError $e) {
    echo $e->getMessage(), "\n";
}
