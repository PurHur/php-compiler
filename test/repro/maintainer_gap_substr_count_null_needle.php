<?php

declare(strict_types=1);

try {
    substr_count('haystack', null);
    echo "null_needle: uncaught\n";
} catch (TypeError $e) {
    echo $e->getMessage(), "\n";
}

try {
    substr_count('haystack', '');
    echo "empty_needle: uncaught\n";
} catch (ValueError $e) {
    echo $e->getMessage(), "\n";
}
