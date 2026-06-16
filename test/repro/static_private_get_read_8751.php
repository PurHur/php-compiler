<?php
class Box {
    private(get) static string $secret = 'hidden';
}
try {
    echo Box::$secret, "\n";
    echo "uncaught\n";
} catch (Error $e) {
    echo get_class($e), ': ', $e->getMessage(), "\n";
}
