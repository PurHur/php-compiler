<?php

declare(strict_types=1);

class Ex extends Exception {}

try {
    try {
        throw new Ex('inner');
    } catch (Ex $e) {
        throw;
    }
} catch (Ex $e) {
    echo "inner\n";
}
