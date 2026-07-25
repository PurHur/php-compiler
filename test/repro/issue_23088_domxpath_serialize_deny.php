<?php

/**
 * Repro for #23088 — DOMXPath serialize deny (php-src ext/dom/xpath.c).
 */
$dom = new DOMDocument();
$dom->loadXML('<r/>');
$xpath = new DOMXPath($dom);
try {
    echo serialize($xpath), "\n";
} catch (Throwable $e) {
    echo get_class($e), ':', $e->getMessage(), "\n";
}
try {
    unserialize('O:8:"DOMXPath":0:{}');
    echo "unserialize:no-throw\n";
} catch (Throwable $e) {
    echo get_class($e), ':', $e->getMessage(), "\n";
}
