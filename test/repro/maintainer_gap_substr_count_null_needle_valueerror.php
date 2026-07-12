<?php

try {
    substr_count('abc', null);
    echo "null_needle: uncaught\n";
} catch (ValueError $e) {
    echo $e->getMessage(), "\n";
}

try {
    substr_count('abc', '');
    echo "empty_needle: uncaught\n";
} catch (ValueError $e) {
    echo $e->getMessage(), "\n";
}
