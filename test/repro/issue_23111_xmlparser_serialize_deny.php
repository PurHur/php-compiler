<?php

/**
 * Repro for #23111 — XMLParser serialize deny (php-src ext/xml/xml.stub.php).
 */
$p = xml_parser_create();
try {
    echo serialize($p), "\n";
} catch (Throwable $e) {
    echo get_class($e), ':', $e->getMessage(), "\n";
}
try {
    unserialize('O:9:"XMLParser":0:{}');
    echo "unserialize:no-throw\n";
} catch (Throwable $e) {
    echo get_class($e), ':', $e->getMessage(), "\n";
}
