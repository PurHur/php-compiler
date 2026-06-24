<?php

declare(strict_types=1);

try {
    parse_ini_file('');
    echo "false\n";
} catch (ValueError $e) {
    echo $e->getMessage(), "\n";
}
