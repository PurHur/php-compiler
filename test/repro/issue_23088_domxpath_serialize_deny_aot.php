<?php

/**
 * AOT repro for #23088 — DOMXPath serialize deny (php-src ext/dom/xpath.c).
 */
$dom = new DOMDocument();
$dom->loadXML('<r/>');
$xpath = new DOMXPath($dom);
try {
    echo serialize($xpath), "\n";
} catch (Throwable $e) {
    echo get_class($e), ':', $e->getMessage(), "\n";
}
