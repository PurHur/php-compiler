<?php

declare(strict_types=1);

try {
    parse_str('a=1');
    echo "no throw\n";
} catch (ArgumentCountError $e) {
    echo $e->getMessage(), "\n";
}
