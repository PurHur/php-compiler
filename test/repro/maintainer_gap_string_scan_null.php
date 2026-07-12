<?php

declare(strict_types=1);

try {
    count_chars(null);
    echo "count_chars: uncaught\n";
} catch (TypeError $e) {
    echo $e->getMessage(), "\n";
}

try {
    strspn('abc', null);
    echo "strspn: uncaught\n";
} catch (TypeError $e) {
    echo $e->getMessage(), "\n";
}

try {
    strcspn('abc', null);
    echo "strcspn: uncaught\n";
} catch (TypeError $e) {
    echo $e->getMessage(), "\n";
}
