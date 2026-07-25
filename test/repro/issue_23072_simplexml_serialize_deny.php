<?php

declare(strict_types=1);

$sx = simplexml_load_string('<a/>');
try {
    echo serialize($sx), "\n";
} catch (Throwable $e) {
    echo get_class($e), ':', $e->getMessage(), "\n";
}

try {
    unserialize('O:16:"SimpleXMLElement":0:{}');
    echo "unserialize:no-throw\n";
} catch (Throwable $e) {
    echo get_class($e), ':', $e->getMessage(), "\n";
}
